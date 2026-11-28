<?php

namespace App\Vault\Settings;

use App\Enums\AuditAction;
use App\Models\User;
use App\Models\VaultSetting;
use App\Vault\Audit\AuditLogger;
use App\Vault\Authorization\Ability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The policy settings a company changes from the interface: retention, policies and organisation details. Values live
 * in the vault_settings table, are read once per request and are never cached longer, so a change applies at once.
 * Secrets never belong here.
 */
class VaultSettings
{
    /** Settings whose values are contact details. They are audited as "changed" without the value (invariant 8). */
    private const PERSONAL_PREFIX = 'organisation.';

    /** @var array<string, mixed>|null */
    private ?array $stored = null;

    public function __construct(private AuditLogger $auditLogger) {}

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
     * Get the stored value of a setting, or its default.
     */
    public function get(string $key): mixed
    {
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

                $changes[$key] = str_starts_with($key, self::PERSONAL_PREFIX)
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
            }
        });

        $this->stored = null;
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

        return is_int(self::defaults()[$key] ?? null) || str_contains($key, '_days') ? (int) $value : trim((string) $value);
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
