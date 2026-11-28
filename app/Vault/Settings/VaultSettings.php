<?php

namespace App\Vault\Settings;

use App\Enums\AlertRule;
use App\Enums\AuditAction;
use App\Exceptions\Vault\DecryptionFailedException;
use App\Exceptions\Vault\InvalidAdminIpAllowlistException;
use App\Exceptions\Vault\LockoutGuardException;
use App\Models\User;
use App\Models\VaultSetting;
use App\Vault\Audit\AuditLogger;
use App\Vault\Compliance\SecurityAlerts;
use App\Vault\Crypto\EncryptedSecret;
use App\Vault\Crypto\SecretCipher;
use App\Vault\Tokens\AdminIpList;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * The policy settings a company changes from the interface: retention, policies and organisation details. Values live
 * in the vault_settings table, are read once per request and are never cached longer, so a change applies at once.
 * Secrets never belong here.
 */
class VaultSettings
{
    /** Settings whose values are contact details. They are audited as "changed" without the value (invariant 8). */
    private const PERSONAL_PREFIXES = ['organisation.', 'notifications.'];

    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    /** The settings that hold a secret. They are stored encrypted, never shown again and never audited by value. */
    public const SECRET_KEYS = ['sso.client_secret', 'notifications.slack_webhook', 'mail.password'];

    /** The config key of the environment variable that overrides a secret setting. */
    public const SECRET_ENVIRONMENT = ['sso.client_secret' => 'vault.sso.client_secret', 'notifications.slack_webhook' => 'vault.health.notify_slack_webhook', 'mail.password' => 'vault.env.mail_password'];

    public function __construct(private AuditLogger $auditLogger, private SecretCipher $cipher) {}

    /**
     * The validation rules per setting. Integer minimums are guards against accidental loss of evidence, not legal
     * requirements.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'retention.access_log_days' => ['required', 'integer', 'min:30', 'max:36500'],
            'retention.audit_log_days' => ['required', 'integer', 'min:90', 'max:36500'],
            'retention.invitation_days' => ['required', 'integer', 'min:1', 'max:36500'],
            'policy.token_max_lifetime_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'policy.credential_rotation_days' => ['nullable', 'integer', 'min:30', 'max:36500'],
            'policy.access_review_interval_days' => ['required', 'integer', 'min:30', 'max:36500'],
            'organisation.name' => ['nullable', 'string', 'max:255'],
            'organisation.security_contact' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'organisation.privacy_contact' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'api.rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:100000'],
            'api.failed_attempts_per_minute' => ['required', 'integer', 'min:1', 'max:10000'],
            'admin.allowed_ips' => ['nullable', 'string', 'max:2000', function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    AdminIpList::parse((string) $value);
                } catch (InvalidAdminIpAllowlistException) {
                    $fail(__('Every entry must be an IP address or a CIDR range, separated by commas.'));
                }
            }],
            'security.break_glass_bypasses_ip_allowlist' => ['boolean'],
            'security.session_idle_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'invitations.expire_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'mail.host' => ['nullable', 'string', 'max:255'],
            'mail.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail.encryption' => ['nullable', 'string', Rule::in(['tls', 'ssl', 'none'])],
            'mail.username' => ['nullable', 'string', 'max:255'],
            'mail.from_address' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'mail.from_name' => ['nullable', 'string', 'max:255'],
            'alerts.failed_attempts_threshold' => ['required', 'integer', 'min:3', 'max:10000'],
            'alerts.sso_rejections_threshold' => ['required', 'integer', 'min:3', 'max:10000'],
            ...collect(AlertRule::cases())->mapWithKeys(fn (AlertRule $rule): array => ['alerts.rule.'.$rule->value => ['boolean']])->all(),
            'approval.enabled' => ['boolean'],
            'security.session_absolute_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'security.inactive_deactivate_days' => ['required', 'integer', 'min:7', 'max:3650'],
            'notifications.mail' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'security.log_channel' => ['nullable', 'string', Rule::in(array_keys((array) config('logging.channels')))],
            'sso.enabled' => ['boolean'],
            'sso.issuer' => ['nullable', 'string', 'url:https', 'max:255'],
            'sso.client_id' => ['nullable', 'string', 'max:255'],
            'sso.scopes' => ['required', 'string', 'max:255'],
            'sso.button_label' => ['required', 'string', 'max:60'],
            'sso.groups_claim' => ['required', 'string', 'max:100'],
            'sso.allowed_email_domains' => ['nullable', 'string', 'max:1000'],
            'sso.auto_provision' => ['boolean'],
            'sso.enforce' => ['boolean'],
            'sso.trust_idp_mfa' => ['boolean'],
            'sso.mfa_acr_values' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * The default of every setting.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'retention.access_log_days' => (int) config('vault.access_log_retention_days', 90),
            'retention.audit_log_days' => 365,
            'retention.invitation_days' => 30,
            'policy.token_max_lifetime_days' => null,
            'policy.credential_rotation_days' => null,
            'policy.access_review_interval_days' => 90,
            'organisation.name' => null,
            'organisation.security_contact' => null,
            'organisation.privacy_contact' => null,
            'api.rate_limit_per_minute' => 60,
            'api.failed_attempts_per_minute' => 10,
            'admin.allowed_ips' => null,
            'security.break_glass_bypasses_ip_allowlist' => false,
            'security.session_idle_minutes' => 60,
            'invitations.expire_hours' => 48,
            'mail.host' => null,
            'mail.port' => null,
            'mail.encryption' => null,
            'mail.username' => null,
            'mail.from_address' => null,
            'mail.from_name' => null,
            'alerts.failed_attempts_threshold' => 20,
            'alerts.sso_rejections_threshold' => 10,
            ...collect(AlertRule::cases())->mapWithKeys(fn (AlertRule $rule): array => ['alerts.rule.'.$rule->value => true])->all(),
            'approval.enabled' => false,
            'security.session_absolute_hours' => 12,
            'security.inactive_deactivate_days' => 90,
            'notifications.mail' => null,
            'security.log_channel' => null,
            'sso.enabled' => false,
            'sso.issuer' => null,
            'sso.client_id' => null,
            'sso.scopes' => 'openid email profile',
            'sso.button_label' => 'Sign in with SSO',
            'sso.groups_claim' => 'groups',
            'sso.allowed_email_domains' => null,
            'sso.auto_provision' => false,
            'sso.enforce' => false,
            'sso.trust_idp_mfa' => false,
            'sso.mfa_acr_values' => null,
        ];
    }

    /**
     * The settings an environment variable can override, with the config key that holds the variable. A value set
     * in the environment wins over the dashboard and locks the field.
     *
     * @return array<string, string>
     */
    public static function environmentOverrides(): array
    {
        return [
            'api.rate_limit_per_minute' => 'vault.api.rate_limit_per_minute',
            'api.failed_attempts_per_minute' => 'vault.api.failed_attempts_per_minute',
            'admin.allowed_ips' => 'vault.admin.allowed_ips',
            'security.session_idle_minutes' => 'vault.env.session_lifetime',
            'invitations.expire_hours' => 'vault.invitations.expire_hours',
            'mail.host' => 'vault.env.mail_host',
            'mail.port' => 'vault.env.mail_port',
            'mail.encryption' => 'vault.env.mail_encryption',
            'mail.username' => 'vault.env.mail_username',
            'mail.from_address' => 'vault.env.mail_from_address',
            'mail.from_name' => 'vault.env.mail_from_name',
            'security.session_absolute_hours' => 'vault.admin.session_absolute_hours',
            'security.inactive_deactivate_days' => 'vault.admin.inactive_deactivate_days',
            'notifications.mail' => 'vault.health.notify_mail',
            'security.log_channel' => 'vault.security_log_channel',
            'sso.enabled' => 'vault.sso.enabled',
            'sso.issuer' => 'vault.sso.issuer',
            'sso.client_id' => 'vault.sso.client_id',
            'sso.scopes' => 'vault.sso.scopes',
            'sso.button_label' => 'vault.sso.button_label',
            'sso.groups_claim' => 'vault.sso.groups_claim',
            'sso.allowed_email_domains' => 'vault.sso.allowed_email_domains',
            'sso.auto_provision' => 'vault.sso.auto_provision',
            'sso.enforce' => 'vault.sso.enforce',
            'sso.trust_idp_mfa' => 'vault.sso.trust_idp_mfa',
            'sso.mfa_acr_values' => 'vault.sso.mfa_acr_values',
        ];
    }

    public function accessLogRetentionDays(): int
    {
        return (int) $this->get('retention.access_log_days');
    }

    public function auditLogRetentionDays(): int
    {
        return (int) $this->get('retention.audit_log_days');
    }

    public function invitationRetentionDays(): int
    {
        return (int) $this->get('retention.invitation_days');
    }

    public function tokenMaxLifetimeDays(): ?int
    {
        return $this->nullableInt('policy.token_max_lifetime_days');
    }

    public function credentialRotationDays(): ?int
    {
        return $this->nullableInt('policy.credential_rotation_days');
    }

    public function accessReviewIntervalDays(): int
    {
        return (int) $this->get('policy.access_review_interval_days');
    }

    public function apiRateLimitPerMinute(): int
    {
        return (int) $this->get('api.rate_limit_per_minute');
    }

    public function apiFailedAttemptsPerMinute(): int
    {
        return (int) $this->get('api.failed_attempts_per_minute');
    }

    public function invitationExpireHours(): int
    {
        return (int) $this->get('invitations.expire_hours');
    }

    public function sessionAbsoluteHours(): int
    {
        return (int) $this->get('security.session_absolute_hours');
    }

    public function inactiveDeactivateDays(): int
    {
        return (int) $this->get('security.inactive_deactivate_days');
    }

    public function notificationMail(): ?string
    {
        return $this->nullableString('notifications.mail');
    }

    public function securityLogChannel(): ?string
    {
        return $this->nullableString('security.log_channel');
    }

    public function organisationName(): ?string
    {
        return $this->nullableString('organisation.name');
    }

    public function securityContact(): ?string
    {
        return $this->nullableString('organisation.security_contact');
    }

    public function privacyContact(): ?string
    {
        return $this->nullableString('organisation.privacy_contact');
    }

    /**
     * The keys of the settings that have a stored value, secrets included.
     *
     * @return list<string>
     */
    public function storedKeys(): array
    {
        return array_keys($this->values());
    }

    /**
     * Determine whether a stored value still passes the rules of its definition, for the health check: a rule can
     * change in an upgrade.
     */
    public function storedValueIsValid(string $key): bool
    {
        if (! array_key_exists($key, self::rules()) || ! array_key_exists($key, $this->values())) {
            return true;
        }

        try {
            $this->validate([$key => $this->values()[$key]], self::rules());
        } catch (ValidationException) {
            return false;
        }

        return true;
    }

    /**
     * Where the value of a setting comes from: the environment, the dashboard or the default.
     */
    public function source(string $key): string
    {
        return match (true) {
            in_array($key, self::SECRET_KEYS, true) && $this->secretIsFromEnvironment($key) => 'environment',
            $this->isFromEnvironment($key) => 'environment',
            array_key_exists($key, $this->values()) => 'dashboard',
            default => 'default',
        };
    }

    /**
     * Determine whether an environment variable sets the value, which then locks the dashboard field.
     */
    public function isFromEnvironment(string $key): bool
    {
        $configKey = self::environmentOverrides()[$key] ?? null;

        return $configKey !== null && config($configKey) !== null && config($configKey) !== '';
    }

    /**
     * Get the value of a setting: the environment, then the stored value, then the default.
     */
    public function get(string $key): mixed
    {
        if ($this->isFromEnvironment($key)) {
            return config(self::environmentOverrides()[$key]);
        }

        $values = $this->values();

        return array_key_exists($key, $values) ? $values[$key] : (self::defaults()[$key] ?? null);
    }

    /**
     * Change settings. Every value is validated first and nothing is written when one fails. An empty value for an
     * optional setting returns it to its default. One audit entry holds the old and new value of every changed setting.
     *
     * A critical change that could lock people out or delete evidence is refused, or asks for a confirmation: pass the
     * confirmations as setting key to true, or to the word that was typed.
     *
     * When change approval is on and a second Owner exists, critical settings are not applied: they are stored as
     * pending changes and their keys are returned. The other values are applied.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $confirmations
     * @return list<string> the keys of the changes that now wait for approval
     *
     * @throws ValidationException
     * @throws LockoutGuardException
     */
    public function update(array $values, User $actor, array $confirmations = []): array
    {
        $rules = self::rules();
        $unknown = array_diff(array_keys($values), array_keys($rules));

        if ($unknown !== []) {
            throw ValidationException::withMessages([array_values($unknown)[0] => __('This setting does not exist.')]);
        }

        foreach (array_keys($values) as $key) {
            Gate::forUser($actor)->authorize(SettingsRegistry::get($key)->permission);
        }

        $validated = $this->validate($values, $rules);

        $normalised = [];
        foreach ($validated as $key => $value) {
            $normalised[$key] = $this->normalise($key, $value);
        }

        app(LockoutGuard::class)->assert($normalised, $this->effectiveValues(array_keys($normalised)), $actor, $confirmations);

        $pending = [];
        $approval = app(ChangeApproval::class);

        if ($approval->isRequired()) {
            $current = $this->effectiveValues(array_keys($normalised));

            foreach ($normalised as $key => $value) {
                if (SettingsRegistry::get($key)->isCritical && $value !== $current[$key]) {
                    $approval->propose('setting', $key, ['value' => $value], $actor);
                    $pending[] = $key;
                    unset($normalised[$key]);
                }
            }
        }

        $this->write($normalised, $actor);

        return $pending;
    }

    /**
     * Apply a setting that a second Owner approved. The permission and the guards ran when it was proposed.
     */
    public function applyApproved(string $key, mixed $value, User $approver): void
    {
        $this->write([$key => $value], $approver);
    }

    /**
     * Store a secret that a second Owner approved. The ciphertext was made, bound to its setting, when it was proposed.
     *
     * @param  array<string, mixed>  $stored
     */
    public function applyApprovedSecret(string $key, array $stored, User $approver): void
    {
        DB::transaction(function () use ($key, $stored, $approver): void {
            VaultSetting::query()->updateOrCreate(['key' => $key], ['value' => $stored]);

            $this->auditLogger->record(AuditAction::SettingsChanged, null, ['changes' => [$key => ['old' => 'changed', 'new' => 'changed']]], $approver);
        });

        $this->stored = null;
    }

    /**
     * Change settings from the console, for an operator with shell access. It is the recovery path, so it has no
     * actor, no permission check and no lockout guard. The audit entry says the console did it, and every Owner is told.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function updateFromConsole(array $values): void
    {
        $rules = self::rules();
        $unknown = array_diff(array_keys($values), array_keys($rules));

        if ($unknown !== []) {
            throw ValidationException::withMessages([array_values($unknown)[0] => __('This setting does not exist.')]);
        }

        $validated = $this->validate($values, $rules);

        $normalised = [];
        foreach ($validated as $key => $value) {
            $normalised[$key] = $this->normalise($key, $value);
        }

        $this->write($normalised, null);
    }

    /**
     * Return a setting to the value of the environment, or its default, from the console.
     */
    public function resetFromConsole(string $key): void
    {
        if (in_array($key, self::SECRET_KEYS, true)) {
            $this->clearSecretFromConsole($key);

            return;
        }

        if (! array_key_exists($key, self::rules())) {
            throw ValidationException::withMessages([$key => __('This setting does not exist.')]);
        }

        $this->write([$key => self::defaults()[$key] ?? null], null);
    }

    /**
     * The value each given setting has now, ignoring the environment, for the lockout guard to compare with.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function effectiveValues(array $keys): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values[$key] = $this->get($key);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $normalised
     */
    private function write(array $normalised, ?User $actor): void
    {
        DB::transaction(function () use ($normalised, $actor): void {
            $changes = [];
            $current = $this->values(fresh: true);

            foreach ($normalised as $key => $value) {
                $old = array_key_exists($key, $current) ? $current[$key] : (self::defaults()[$key] ?? null);

                if ($value === $old) {
                    continue;
                }

                $changes[$key] = Str::startsWith($key, self::PERSONAL_PREFIXES)
                    ? ['old' => 'changed', 'new' => 'changed']
                    : ['old' => $old, 'new' => $value];

                if ($value === (self::defaults()[$key] ?? null)) {
                    VaultSetting::query()->whereKey($key)->delete();
                } else {
                    VaultSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
                }
            }

            if ($changes === []) {
                return;
            }

            $this->auditLogger->record(AuditAction::SettingsChanged, null, $actor === null ? ['changes' => $changes, 'source' => 'console'] : ['changes' => $changes], $actor);

            $this->raiseAlerts(array_keys($changes), $actor);
        });

        $this->stored = null;
    }

    /**
     * @param  list<string>  $keys
     */
    private function raiseAlerts(array $keys, ?User $actor): void
    {
        $policyKeys = array_values(array_filter($keys, fn (string $key): bool => Str::startsWith($key, ['retention.', 'policy.', 'security.'])));
        $by = $actor === null ? 'the console' : 'admin #'.$actor->getKey();

        if ($actor === null) {
            DB::afterCommit(fn () => app(SecurityAlerts::class)->send(AlertRule::SettingsChange, 'console:'.implode(',', $keys), 'These settings changed from the console: '.implode(', ', $keys).'.', app(SecurityAlerts::class)->otherOwners()));

            return;
        }

        if ($policyKeys !== []) {
            DB::afterCommit(fn () => app(SecurityAlerts::class)->send(AlertRule::SettingsChange, implode(',', $policyKeys), "These settings changed by {$by}: ".implode(', ', $policyKeys).'.'));
        }
    }

    /**
     * Determine whether a secret setting has a value, from the environment or the dashboard.
     */
    public function hasSecret(string $key): bool
    {
        return $this->secret($key) !== null;
    }

    /**
     * Determine whether an environment variable sets the secret.
     */
    public function secretIsFromEnvironment(string $key): bool
    {
        return filled(config(self::SECRET_ENVIRONMENT[$key] ?? ''));
    }

    /**
     * Get a secret in plain text: the environment value, or the stored one decrypted. Only the code that talks to the
     * identity provider may call this.
     */
    public function secret(string $key): ?string
    {
        if ($this->secretIsFromEnvironment($key)) {
            return (string) config(self::SECRET_ENVIRONMENT[$key]);
        }

        $stored = $this->values()[$key] ?? null;

        if (! is_array($stored) || ! isset($stored['secret'], $stored['data_key'], $stored['key_version'])) {
            return null;
        }

        try {
            return $this->cipher->decrypt(self::secretContext($key), new EncryptedSecret(
                base64_decode((string) $stored['secret']),
                base64_decode((string) $stored['data_key']),
                (int) $stored['key_version'],
            ));
        } catch (DecryptionFailedException) {
            return null;
        }
    }

    /**
     * Store a secret setting encrypted, or clear it with an empty value. The audit entry says the secret changed and
     * never holds it. When change approval applies to a critical secret, it is stored encrypted as a pending change
     * instead, and true is returned.
     *
     * @throws ValidationException
     */
    public function setSecret(string $key, #[SensitiveParameter] ?string $plain, User $actor): bool
    {
        if (! in_array($key, self::SECRET_KEYS, true)) {
            throw ValidationException::withMessages([$key => __('This setting does not exist.')]);
        }

        Gate::forUser($actor)->authorize(SettingsRegistry::get($key)->permission);

        $approval = app(ChangeApproval::class);

        if ($plain !== null && $plain !== '' && SettingsRegistry::get($key)->isCritical && $approval->isRequired()) {
            $encrypted = $this->cipher->encrypt(self::secretContext($key), $plain);

            $approval->propose('secret', $key, [
                'secret' => base64_encode($encrypted->secretCiphertext),
                'data_key' => base64_encode($encrypted->dataKeyCiphertext),
                'key_version' => $encrypted->keyVersion,
            ], $actor);

            return true;
        }

        $this->storeSecret($key, $plain, $actor);

        return false;
    }

    /**
     * Store or clear a secret from the console. There is no actor and no permission check, because the console is the
     * recovery path, and the audit entry says so.
     *
     * @throws ValidationException
     */
    public function setSecretFromConsole(string $key, #[SensitiveParameter] string $plain): void
    {
        if (! in_array($key, self::SECRET_KEYS, true)) {
            throw ValidationException::withMessages([$key => __('This setting does not exist.')]);
        }

        $this->storeSecret($key, $plain, null);
    }

    private function clearSecretFromConsole(string $key): void
    {
        $this->storeSecret($key, null, null);
    }

    private function storeSecret(string $key, #[SensitiveParameter] ?string $plain, ?User $actor): void
    {
        DB::transaction(function () use ($key, $plain, $actor): void {
            if ($plain === null || $plain === '') {
                VaultSetting::query()->whereKey($key)->delete();
            } else {
                $encrypted = $this->cipher->encrypt(self::secretContext($key), $plain);

                VaultSetting::query()->updateOrCreate(['key' => $key], ['value' => [
                    'secret' => base64_encode($encrypted->secretCiphertext),
                    'data_key' => base64_encode($encrypted->dataKeyCiphertext),
                    'key_version' => $encrypted->keyVersion,
                ]]);
            }

            $this->auditLogger->record(AuditAction::SettingsChanged, null, $actor === null ? ['changes' => [$key => ['old' => 'changed', 'new' => 'changed']], 'source' => 'console'] : ['changes' => [$key => ['old' => 'changed', 'new' => 'changed']]], $actor);
        });

        $this->stored = null;
    }

    /**
     * The identifier that binds a stored secret to its setting, so it cannot be moved to another one.
     */
    public static function secretContext(string $key): string
    {
        return 'composer-vault:setting:'.$key;
    }

    /**
     * Validate the values. A dot in a key would be read as nesting, so the keys are swapped for plain names while the
     * validator runs. An empty string counts as no value.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $values, array $rules): array
    {
        $data = [];
        $plainRules = [];
        $normalised = [];

        foreach ($values as $key => $value) {
            $plain = str_replace('.', '__', $key);
            $normalised[$key] = $value === '' ? null : $value;
            $data[$plain] = $normalised[$key];
            $plainRules[$plain] = $rules[$key];
        }

        $validator = Validator::make($data, $plainRules);

        if ($validator->fails()) {
            $messages = [];

            foreach ($validator->errors()->messages() as $plain => $errors) {
                $messages[str_replace('__', '.', $plain)] = str_replace($plain, str_replace('__', '.', $plain), $errors[0]);
            }

            throw ValidationException::withMessages($messages);
        }

        return $normalised;
    }

    /**
     * @return array<string, mixed>
     */
    private function values(bool $fresh = false): array
    {
        if ($this->stored === null || $fresh) {
            try {
                $this->stored = VaultSetting::query()->pluck('value', 'key')->all();
            } catch (QueryException) {
                // The table does not exist yet (a fresh install, before the migrations): every setting is at its default.
                $this->stored = [];
            }
        }

        return $this->stored;
    }

    private function normalise(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $default = self::defaults()[$key] ?? null;

        return match (true) {
            is_bool($default) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            is_int($default) || str_contains($key, '_days') || $key === 'mail.port' => (int) $value,
            default => trim((string) $value),
        };
    }

    private function nullableInt(string $key): ?int
    {
        $value = $this->get($key);

        return $value === null ? null : (int) $value;
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
