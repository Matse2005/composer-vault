<?php

namespace App\Vault\Settings;

use App\Enums\AlertRule;
use App\Enums\AuditAction;
use App\Exceptions\Vault\DecryptionFailedException;
use App\Models\User;
use App\Models\VaultSetting;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use App\Vault\Compliance\SecurityAlerts;
use App\Vault\Crypto\EncryptedSecret;
use App\Vault\Crypto\SecretCipher;
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
    public const SECRET_KEYS = ['sso.client_secret', 'notifications.slack_webhook'];

    /** The config key of the environment variable that overrides a secret setting. */
    public const SECRET_ENVIRONMENT = ['sso.client_secret' => 'vault.sso.client_secret', 'notifications.slack_webhook' => 'vault.health.notify_slack_webhook'];

    public function __construct(private AuditLogger $auditLogger, private SecretCipher $cipher) {}

    /**
     * The validation rules per setting. Integer minimums are guards against accidental loss of evidence, not legal
     * requirements.
     *
     * @return array<string, list<string>>
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
            'sso.owner_groups' => ['nullable', 'string', 'max:1000'],
            'sso.admin_groups' => ['nullable', 'string', 'max:1000'],
            'sso.auditor_groups' => ['nullable', 'string', 'max:1000'],
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
            'sso.owner_groups' => null,
            'sso.admin_groups' => null,
            'sso.auditor_groups' => null,
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
            'sso.owner_groups' => 'vault.sso.role_groups.owner',
            'sso.admin_groups' => 'vault.sso.role_groups.admin',
            'sso.auditor_groups' => 'vault.sso.role_groups.auditor',
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
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function update(array $values, User $actor): void
    {
        Gate::forUser($actor)->authorize(Ability::ManageSettings);

        $rules = self::rules();
        $unknown = array_diff(array_keys($values), array_keys($rules));

        if ($unknown !== []) {
            throw ValidationException::withMessages([array_values($unknown)[0] => __('This setting does not exist.')]);
        }

        $validated = $this->validate($values, $rules);

        DB::transaction(function () use ($validated, $actor): void {
            $changes = [];
            $current = $this->values(fresh: true);

            foreach ($validated as $key => $value) {
                $value = $this->normalise($key, $value);
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

            if ($changes !== []) {
                $this->auditLogger->record(AuditAction::SettingsChanged, null, ['changes' => $changes], $actor);

                $policyKeys = array_values(array_filter(array_keys($changes), fn (string $key): bool => Str::startsWith($key, ['retention.', 'policy.', 'security.'])));

                if ($policyKeys !== []) {
                    DB::afterCommit(fn () => app(SecurityAlerts::class)->send(AlertRule::SettingsChange, implode(',', $policyKeys), 'These settings changed by admin #'.$actor->getKey().': '.implode(', ', $policyKeys).'.'));
                }
            }
        });

        $this->stored = null;
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
     * never holds it.
     *
     * @throws ValidationException
     */
    public function setSecret(string $key, #[SensitiveParameter] ?string $plain, User $actor): void
    {
        Gate::forUser($actor)->authorize(Ability::ManageSettings);

        if (! in_array($key, self::SECRET_KEYS, true)) {
            throw ValidationException::withMessages([$key => __('This setting does not exist.')]);
        }

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

            $this->auditLogger->record(AuditAction::SettingsChanged, null, ['changes' => [$key => ['old' => 'changed', 'new' => 'changed']]], $actor);
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
     * @param  array<string, list<string>>  $rules
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
            $this->stored = VaultSetting::query()->pluck('value', 'key')->all();
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
            is_int($default) || str_contains($key, '_days') => (int) $value,
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
