<?php

use App\Vault\Settings\VaultSettings;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('API')] class extends Component {
    public string $rateLimit = '';

    public string $failedAttempts = '';

    public function mount(VaultSettings $settings): void
    {
        $this->rateLimit = (string) $settings->apiRateLimitPerMinute();
        $this->failedAttempts = (string) $settings->apiFailedAttemptsPerMinute();
    }

    public function isLocked(string $key): bool
    {
        return app(VaultSettings::class)->isFromEnvironment($key);
    }

    public function save(VaultSettings $settings): void
    {
        $this->resetErrorBag();
        $values = [];

        if (! $settings->isFromEnvironment('api.rate_limit_per_minute')) {
            $values['api.rate_limit_per_minute'] = $this->rateLimit;
        }

        if (! $settings->isFromEnvironment('api.failed_attempts_per_minute')) {
            $values['api.failed_attempts_per_minute'] = $this->failedAttempts;
        }

        try {
            $settings->update($values, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($key === 'api.rate_limit_per_minute' ? 'rateLimit' : 'failedAttempts', $messages[0]);
            }

            return;
        }

        Flux::toast(__('API limits saved.'));
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('API') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Limits for the endpoint that servers and pipelines use to fetch credentials.') }}</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div>
            <flux:input wire:model="rateLimit" type="number" min="1" :label="__('Requests per minute and token')" :description="__('A token that makes more requests gets a 429 until the minute is over.')" :disabled="$this->isLocked('api.rate_limit_per_minute')" />
            <x-setting-source setting="api.rate_limit_per_minute" />
            <flux:error name="rateLimit" />
        </div>

        <div>
            <flux:input wire:model="failedAttempts" type="number" min="1" :label="__('Failed attempts per minute and address')" :description="__('An address with more failed attempts (401 or 403) is blocked for the rest of the minute.')" :disabled="$this->isLocked('api.failed_attempts_per_minute')" />
            <x-setting-source setting="api.failed_attempts_per_minute" />
            <flux:error name="failedAttempts" />
        </div>

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>
