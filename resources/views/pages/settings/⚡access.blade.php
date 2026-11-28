<?php

use App\Vault\Settings\VaultSettings;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Security')] class extends Component {
    private const FIELDS = [
        'sessionIdleMinutes' => 'security.session_idle_minutes',
        'sessionAbsoluteHours' => 'security.session_absolute_hours',
        'inactiveDeactivateDays' => 'security.inactive_deactivate_days',
        'invitationExpireHours' => 'invitations.expire_hours',
        'adminAllowedIps' => 'admin.allowed_ips',
        'securityLogChannel' => 'security.log_channel',
        'breakGlassBypass' => 'security.break_glass_bypasses_ip_allowlist',
    ];

    public string $sessionIdleMinutes = '';

    public string $sessionAbsoluteHours = '';

    public string $inactiveDeactivateDays = '';

    public string $invitationExpireHours = '';

    public string $adminAllowedIps = '';

    public string $securityLogChannel = '';

    public bool $breakGlassBypass = false;

    public function mount(VaultSettings $settings): void
    {
        $this->sessionIdleMinutes = (string) $settings->get('security.session_idle_minutes');
        $this->sessionAbsoluteHours = (string) $settings->sessionAbsoluteHours();
        $this->inactiveDeactivateDays = (string) $settings->inactiveDeactivateDays();
        $this->invitationExpireHours = (string) $settings->invitationExpireHours();
        $this->adminAllowedIps = (string) $settings->get('admin.allowed_ips');
        $this->securityLogChannel = (string) $settings->securityLogChannel();
        $this->breakGlassBypass = (bool) $settings->get('security.break_glass_bypasses_ip_allowlist');
    }

    /**
     * The log channels of config/logging.php that can receive the security events.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        return array_keys((array) config('logging.channels'));
    }

    public function isLocked(string $key): bool
    {
        return app(VaultSettings::class)->isFromEnvironment($key);
    }

    public function save(VaultSettings $settings): void
    {
        $this->resetErrorBag();
        $values = [];

        foreach (self::FIELDS as $property => $key) {
            if (! $settings->isFromEnvironment($key)) {
                $values[$key] = $this->{$property};
            }
        }

        try {
            $settings->update($values, auth()->user());
        } catch (ValidationException $exception) {
            $fields = array_flip(self::FIELDS);

            foreach ($exception->errors() as $key => $messages) {
                $this->addError($fields[$key] ?? 'sessionIdleMinutes', $messages[0]);
            }

            return;
        }

        Flux::toast(__('Security settings saved.'));
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Security') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Limits that apply to every admin. They narrow the window in which a person who left can still use the vault.') }}</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div>
            <flux:input wire:model="adminAllowedIps" :label="__('Admin IP allowlist')" :description="__('Comma separated IP addresses or CIDR ranges that may reach the admin interface. Empty allows every address. Behind a proxy, TRUSTED_PROXIES must be set.')" :disabled="$this->isLocked('admin.allowed_ips')" />
            <x-setting-source setting="admin.allowed_ips" />
            <flux:error name="adminAllowedIps" />
        </div>

        <div>
            <flux:switch wire:model="breakGlassBypass" :label="__('Break-glass Owners bypass the IP allowlist')" :disabled="$this->isLocked('security.break_glass_bypasses_ip_allowlist')" />
            <x-setting-source setting="security.break_glass_bypasses_ip_allowlist" />
        </div>

        <div>
            <flux:input wire:model="sessionIdleMinutes" type="number" min="5" max="1440" :label="__('Session idle timeout in minutes')" :disabled="$this->isLocked('security.session_idle_minutes')" />
            <x-setting-source setting="security.session_idle_minutes" />
            <flux:error name="sessionIdleMinutes" />
        </div>

        <div>
            <flux:input wire:model="sessionAbsoluteHours" type="number" min="1" max="720" :label="__('Session lifetime in hours')" :description="__('A session ends this long after the login, however active it is. With single sign-on this forces a new check at the identity provider.')" :disabled="$this->isLocked('security.session_absolute_hours')" />
            <x-setting-source setting="security.session_absolute_hours" />
            <flux:error name="sessionAbsoluteHours" />
        </div>

        <div>
            <flux:input wire:model="inactiveDeactivateDays" type="number" min="7" max="3650" :label="__('Deactivate after inactivity in days')" :description="__('An admin who has not logged in for this long is deactivated every night. Break-glass accounts and the last Owner are skipped.')" :disabled="$this->isLocked('security.inactive_deactivate_days')" />
            <x-setting-source setting="security.inactive_deactivate_days" />
            <flux:error name="inactiveDeactivateDays" />
        </div>

        <div>
            <flux:input wire:model="invitationExpireHours" type="number" min="1" max="720" :label="__('Invitation lifetime in hours')" :disabled="$this->isLocked('invitations.expire_hours')" />
            <x-setting-source setting="invitations.expire_hours" />
            <flux:error name="invitationExpireHours" />
        </div>

        <div>
            <flux:select wire:model="securityLogChannel" :label="__('Security log channel')" :description="__('Every audit entry and every failed API access is also written to this log channel as one JSON line, for a SIEM. The lines hold no names and no audit details.')" :disabled="$this->isLocked('security.log_channel')">
                <flux:select.option value="">{{ __('Off') }}</flux:select.option>
                @foreach ($this->channels() as $channel)
                    <flux:select.option :value="$channel">{{ $channel }}</flux:select.option>
                @endforeach
            </flux:select>
            <x-setting-source setting="security.log_channel" />
            <flux:error name="securityLogChannel" />
        </div>

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>
