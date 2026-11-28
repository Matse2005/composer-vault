<?php

use App\Vault\Settings\VaultSettings;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Access policy')] class extends Component {
    public string $sessionAbsoluteHours = '';

    public string $inactiveDeactivateDays = '';

    public function mount(VaultSettings $settings): void
    {
        $this->sessionAbsoluteHours = (string) $settings->sessionAbsoluteHours();
        $this->inactiveDeactivateDays = (string) $settings->inactiveDeactivateDays();
    }

    public function isLocked(string $key): bool
    {
        return app(VaultSettings::class)->isFromEnvironment($key);
    }

    public function save(VaultSettings $settings): void
    {
        $this->resetErrorBag();
        $values = [];

        if (! $settings->isFromEnvironment('security.session_absolute_hours')) {
            $values['security.session_absolute_hours'] = $this->sessionAbsoluteHours;
        }

        if (! $settings->isFromEnvironment('security.inactive_deactivate_days')) {
            $values['security.inactive_deactivate_days'] = $this->inactiveDeactivateDays;
        }

        try {
            $settings->update($values, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($key === 'security.session_absolute_hours' ? 'sessionAbsoluteHours' : 'inactiveDeactivateDays', $messages[0]);
            }

            return;
        }

        Flux::toast(__('Access policy saved.'));
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Access policy') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Limits that apply to every admin. They narrow the window in which a person who left can still use the vault.') }}</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div>
            <flux:input wire:model="sessionAbsoluteHours" type="number" min="1" max="720" :label="__('Session lifetime in hours')" :description="__('A session ends this long after the login, however active it is. With single sign-on this forces a new check at the identity provider.')" :disabled="$this->isLocked('security.session_absolute_hours')" />
            @if ($this->isLocked('security.session_absolute_hours'))
                <flux:text class="mt-1 text-xs">{{ __('Set by the environment.') }}</flux:text>
            @endif
        </div>

        <div>
            <flux:input wire:model="inactiveDeactivateDays" type="number" min="7" max="3650" :label="__('Deactivate after inactivity in days')" :description="__('An admin who has not logged in for this long is deactivated every night. Break-glass accounts and the last Owner are skipped.')" :disabled="$this->isLocked('security.inactive_deactivate_days')" />
            @if ($this->isLocked('security.inactive_deactivate_days'))
                <flux:text class="mt-1 text-xs">{{ __('Set by the environment.') }}</flux:text>
            @endif
        </div>

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>