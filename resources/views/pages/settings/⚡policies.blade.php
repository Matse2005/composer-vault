<?php

use App\Vault\Settings\VaultSettings;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Policies')] class extends Component {
    public string $tokenMaxLifetimeDays = '';

    public string $credentialRotationDays = '';

    public string $accessReviewIntervalDays = '';

    public function mount(VaultSettings $settings): void
    {
        $this->tokenMaxLifetimeDays = (string) $settings->tokenMaxLifetimeDays();
        $this->credentialRotationDays = (string) $settings->credentialRotationDays();
        $this->accessReviewIntervalDays = (string) $settings->accessReviewIntervalDays();
    }

    public function save(VaultSettings $settings): void
    {
        $this->resetErrorBag();

        try {
            $settings->update([
                'policy.token_max_lifetime_days' => $this->tokenMaxLifetimeDays,
                'policy.credential_rotation_days' => $this->credentialRotationDays,
                'policy.access_review_interval_days' => $this->accessReviewIntervalDays,
            ], auth()->user());
        } catch (ValidationException $exception) {
            $fields = ['policy.token_max_lifetime_days' => 'tokenMaxLifetimeDays', 'policy.credential_rotation_days' => 'credentialRotationDays', 'policy.access_review_interval_days' => 'accessReviewIntervalDays'];

            foreach ($exception->errors() as $key => $messages) {
                $this->addError($fields[$key] ?? 'tokenMaxLifetimeDays', $messages[0]);
            }

            return;
        }

        Flux::toast(__('Policies saved.'));
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Policies') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Rules that the vault enforces or reminds you of. Leave a field empty for no limit.') }}</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="tokenMaxLifetimeDays" type="number" min="1" :label="__('Maximum token lifetime in days')" :description="__('A new token needs an expiry date within this many days. Existing tokens beyond it show up in the access review.')" />
        <flux:error name="tokenMaxLifetimeDays" />

        <flux:input wire:model="credentialRotationDays" type="number" min="30" :label="__('Credential rotation in days')" :description="__('The access review lists credentials whose secret did not change for this long.')" />
        <flux:error name="credentialRotationDays" />

        <flux:input wire:model="accessReviewIntervalDays" type="number" min="30" :label="__('Access review interval in days')" :description="__('The health checks warn when the last review is older.')" />
        <flux:error name="accessReviewIntervalDays" />

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>
