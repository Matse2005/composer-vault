<?php

use App\Vault\Settings\VaultSettings;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Organisation')] class extends Component {
    public string $name = '';

    public string $securityContact = '';

    public string $privacyContact = '';

    public function mount(VaultSettings $settings): void
    {
        $this->name = (string) $settings->organisationName();
        $this->securityContact = (string) $settings->securityContact();
        $this->privacyContact = (string) $settings->privacyContact();
    }

    public function save(VaultSettings $settings): void
    {
        $this->resetErrorBag();

        try {
            $settings->update([
                'organisation.name' => $this->name,
                'organisation.security_contact' => $this->securityContact,
                'organisation.privacy_contact' => $this->privacyContact,
            ], auth()->user());
        } catch (\Illuminate\Validation\ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $field = match ($key) {
                    'organisation.name' => 'name',
                    'organisation.security_contact' => 'securityContact',
                    default => 'privacyContact',
                };
                $this->addError($field, $messages[0]);
            }

            return;
        }

        Flux::toast(__('Organisation details saved.'));
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Organisation') }}</flux:heading>
        <flux:text class="mt-2">{{ __('These details appear on the compliance pages. The contact addresses are not written to the audit log.') }}</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" :label="__('Organisation name')" type="text" autocomplete="organization" />
        <flux:input wire:model="securityContact" :label="__('Security contact')" type="email" :description="__('Where people report a security problem.')" />
        <flux:input wire:model="privacyContact" :label="__('Privacy contact')" type="email" :description="__('Where people direct questions about personal data.')" />

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>
