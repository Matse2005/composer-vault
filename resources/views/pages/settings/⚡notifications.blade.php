<?php

use App\Enums\AlertRule;
use App\Vault\Settings\VaultSettings;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] class extends Component {
    public string $mail = '';

    /** The new Slack webhook. It is never filled from the stored value and is emptied after saving. */
    public string $slackWebhook = '';

    public function mount(VaultSettings $settings): void
    {
        $this->mail = (string) $settings->notificationMail();
    }

    public function mailIsLocked(): bool
    {
        return app(VaultSettings::class)->isFromEnvironment('notifications.mail');
    }

    public function webhookState(): string
    {
        $settings = app(VaultSettings::class);

        return match (true) {
            $settings->secretIsFromEnvironment('notifications.slack_webhook') => 'environment',
            $settings->hasSecret('notifications.slack_webhook') => 'set',
            default => 'not set',
        };
    }

    /**
     * Determine whether at least one channel is set, which is what makes the alert rules active.
     */
    public function hasChannel(): bool
    {
        $settings = app(VaultSettings::class);

        return $settings->notificationMail() !== null || $settings->hasSecret('notifications.slack_webhook');
    }

    /**
     * @return list<AlertRule>
     */
    public function rules(): array
    {
        return AlertRule::cases();
    }

    public function save(VaultSettings $settings): void
    {
        $this->resetErrorBag();

        try {
            if (! $settings->isFromEnvironment('notifications.mail')) {
                $settings->update(['notifications.mail' => $this->mail], auth()->user());
            }

            if ($this->slackWebhook !== '' && ! $settings->secretIsFromEnvironment('notifications.slack_webhook')) {
                $settings->setSecret('notifications.slack_webhook', $this->slackWebhook, auth()->user());
            }
        } catch (ValidationException $exception) {
            $this->addError('mail', collect($exception->errors())->flatten()->first());

            return;
        } finally {
            $this->reset('slackWebhook');
        }

        Flux::toast(__('Notification settings saved.'));
    }

    public function clearWebhook(VaultSettings $settings): void
    {
        $settings->setSecret('notifications.slack_webhook', null, auth()->user());
        Flux::toast(__('Slack webhook removed.'));
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Notifications') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Where failing health checks and security alerts are sent. Messages hold rule names, times, addresses and IDs, never a secret. Slack messages never hold a name or email address.') }}</flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div>
            <flux:input wire:model="mail" type="email" :label="__('Mail address')" :disabled="$this->mailIsLocked()" />
            @if ($this->mailIsLocked())
                <flux:text class="mt-1 text-xs">{{ __('Set by the environment.') }}</flux:text>
            @endif
            <flux:error name="mail" />
        </div>

        <div class="space-y-2">
            <flux:input wire:model="slackWebhook" type="password" autocomplete="off" :label="__('Slack incoming webhook')" :disabled="$this->webhookState() === 'environment'" :placeholder="$this->webhookState() === 'set' ? __('Set. Type a new value to replace it.') : ''" />
            <flux:text class="text-xs" data-test="webhook-state">
                @if ($this->webhookState() === 'environment')
                    {{ __('Set by the environment.') }}
                @elseif ($this->webhookState() === 'set')
                    {{ __('A webhook is stored, encrypted. It is never shown again.') }}
                @else
                    {{ __('No webhook is set.') }}
                @endif
            </flux:text>
            @if ($this->webhookState() === 'set')
                <flux:button size="sm" wire:click="clearWebhook" wire:confirm="{{ __('Remove the Slack webhook?') }}">{{ __('Remove webhook') }}</flux:button>
            @endif
        </div>

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Alert rules') }}</flux:heading>
        <flux:text>
            {{ $this->hasChannel() ? __('The rules below are active. Each fires at most once an hour for the same subject. The thresholds are fixed.') : __('No channel is set, so no alert is sent.') }}
        </flux:text>

        <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->rules() as $rule)
                <li class="flex items-start justify-between gap-4 px-4 py-3" wire:key="rule-{{ $rule->value }}">
                    <div>
                        <div class="font-medium">{{ __($rule->label()) }}</div>
                        <div class="text-sm text-zinc-600 dark:text-zinc-400">{{ __($rule->trigger()) }}</div>
                    </div>
                    <flux:badge size="sm" :color="$this->hasChannel() ? 'green' : 'zinc'">{{ $this->hasChannel() ? __('Active') : __('Off') }}</flux:badge>
                </li>
            @endforeach
        </ul>
    </div>
</section>
