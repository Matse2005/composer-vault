<?php

use App\Enums\AlertRule;
use App\Vault\Settings\VaultSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] class extends Component {
    private const TEXT_FIELDS = [
        'mail' => 'notifications.mail',
        'smtpHost' => 'mail.host',
        'smtpPort' => 'mail.port',
        'smtpEncryption' => 'mail.encryption',
        'smtpUsername' => 'mail.username',
        'fromAddress' => 'mail.from_address',
        'fromName' => 'mail.from_name',
        'failedAttemptsThreshold' => 'alerts.failed_attempts_threshold',
        'ssoRejectionsThreshold' => 'alerts.sso_rejections_threshold',
    ];

    public string $mail = '';

    public string $smtpHost = '';

    public string $smtpPort = '';

    public string $smtpEncryption = '';

    public string $smtpUsername = '';

    public string $fromAddress = '';

    public string $fromName = '';

    public string $failedAttemptsThreshold = '';

    public string $ssoRejectionsThreshold = '';

    /** The new SMTP password and Slack webhook. Never filled from a stored value, emptied after saving. */
    public string $smtpPassword = '';

    public string $slackWebhook = '';

    /** @var array<string, bool> */
    public array $rules = [];

    public ?string $testResult = null;

    public function mount(VaultSettings $settings): void
    {
        $this->mail = (string) $settings->notificationMail();
        $this->smtpHost = (string) $settings->get('mail.host');
        $this->smtpPort = (string) $settings->get('mail.port');
        $this->smtpEncryption = (string) $settings->get('mail.encryption');
        $this->smtpUsername = (string) $settings->get('mail.username');
        $this->fromAddress = (string) $settings->get('mail.from_address');
        $this->fromName = (string) $settings->get('mail.from_name');
        $this->failedAttemptsThreshold = (string) $settings->get('alerts.failed_attempts_threshold');
        $this->ssoRejectionsThreshold = (string) $settings->get('alerts.sso_rejections_threshold');

        foreach (AlertRule::cases() as $rule) {
            $this->rules[$rule->value] = (bool) $settings->get('alerts.rule.'.$rule->value);
        }
    }

    public function isLocked(string $key): bool
    {
        return app(VaultSettings::class)->isFromEnvironment($key);
    }

    public function smtpIsActive(): bool
    {
        return config('mail.default') === 'smtp';
    }

    public function secretState(string $key): string
    {
        $settings = app(VaultSettings::class);

        return match (true) {
            $settings->secretIsFromEnvironment($key) => 'environment',
            $settings->hasSecret($key) => 'set',
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
    public function alertRules(): array
    {
        return AlertRule::cases();
    }

    public function save(VaultSettings $settings): void
    {
        $this->resetErrorBag();
        $values = [];

        foreach (self::TEXT_FIELDS as $property => $key) {
            if (! $settings->isFromEnvironment($key) && ! ($this->smtpIsActive() === false && str_starts_with($key, 'mail.'))) {
                $values[$key] = $this->{$property};
            }
        }

        foreach (AlertRule::cases() as $rule) {
            $values['alerts.rule.'.$rule->value] = (bool) ($this->rules[$rule->value] ?? true);
        }

        try {
            $settings->update($values, auth()->user());

            foreach (['smtpPassword' => 'mail.password', 'slackWebhook' => 'notifications.slack_webhook'] as $property => $key) {
                if ($this->{$property} !== '' && ! $settings->secretIsFromEnvironment($key) && ($key !== 'mail.password' || $this->smtpIsActive())) {
                    $settings->setSecret($key, $this->{$property}, auth()->user());
                }
            }
        } catch (ValidationException $exception) {
            $fields = array_flip(self::TEXT_FIELDS);

            foreach ($exception->errors() as $key => $messages) {
                $this->addError($fields[$key] ?? 'mail', $messages[0]);
            }

            return;
        } finally {
            $this->reset('smtpPassword', 'slackWebhook');
        }

        Flux::toast(__('Notification settings saved.'));
    }

    public function clearWebhook(VaultSettings $settings): void
    {
        $settings->setSecret('notifications.slack_webhook', null, auth()->user());
        Flux::toast(__('Slack webhook removed.'));
    }

    /**
     * Send a test mail to the notification address with the stored settings. A failure is reported with the kind of
     * error only: never the password, and no more than the exception's own message.
     */
    public function sendTestMail(VaultSettings $settings): void
    {
        $to = $settings->notificationMail();

        if ($to === null) {
            $this->testResult = __('Set a mail address first.');

            return;
        }

        try {
            Mail::raw(__('This is a test mail from Composer Vault.'), fn ($message) => $message->to($to)->subject(__('Composer Vault test mail')));
            $this->testResult = __('Test mail sent.');
        } catch (Throwable $exception) {
            $this->testResult = __('The test mail failed (:type).', ['type' => class_basename($exception)]);
        }
    }

    /**
     * Post a test message to the stored Slack webhook. A failure is reported with the status code only, never the
     * webhook.
     */
    public function sendTestSlack(VaultSettings $settings): void
    {
        $webhook = $settings->secret('notifications.slack_webhook');

        if ($webhook === null || $webhook === '') {
            $this->testResult = __('Set a Slack webhook first.');

            return;
        }

        try {
            $response = Http::timeout(10)->post($webhook, ['text' => __('This is a test message from Composer Vault.')]);
            $this->testResult = $response->successful() ? __('Test message sent.') : __('The test message failed (status :status).', ['status' => $response->status()]);
        } catch (Throwable $exception) {
            $this->testResult = __('The test message failed (:type).', ['type' => class_basename($exception)]);
        }
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Notifications') }}</flux:heading>
        <flux:text class="mt-2">{{ __('Where failing health checks and security alerts are sent. Messages hold rule names, times, addresses and IDs, never a secret. Slack messages never hold a name or email address.') }}</flux:text>
    </div>

    @if ($testResult)
        <flux:callout icon="information-circle" data-test="test-result"><flux:callout.text>{{ $testResult }}</flux:callout.text></flux:callout>
    @endif

    <form wire:submit="save" class="space-y-8">
        <div class="space-y-6">
            <flux:heading size="lg" level="2">{{ __('Recipients') }}</flux:heading>

            <div>
                <flux:input wire:model="mail" type="email" :label="__('Mail address')" :disabled="$this->isLocked('notifications.mail')" />
                <x-setting-source setting="notifications.mail" />
                <flux:error name="mail" />
            </div>

            <div class="space-y-2">
                <flux:input wire:model="slackWebhook" type="password" autocomplete="off" :label="__('Slack incoming webhook')" :disabled="$this->secretState('notifications.slack_webhook') === 'environment'" :placeholder="$this->secretState('notifications.slack_webhook') === 'set' ? __('Set. Type a new value to replace it.') : ''" />
                <flux:text class="text-xs" data-test="webhook-state">
                    @if ($this->secretState('notifications.slack_webhook') === 'environment')
                        {{ __('Set by the environment.') }}
                    @elseif ($this->secretState('notifications.slack_webhook') === 'set')
                        {{ __('A webhook is stored, encrypted. It is never shown again.') }}
                    @else
                        {{ __('No webhook is set.') }}
                    @endif
                </flux:text>
                @if ($this->secretState('notifications.slack_webhook') === 'set')
                    <flux:button size="sm" wire:click="clearWebhook" wire:confirm="{{ __('Remove the Slack webhook?') }}">{{ __('Remove webhook') }}</flux:button>
                @endif
            </div>
        </div>

        <div class="space-y-6">
            <flux:heading size="lg" level="2">{{ __('Mail transport') }}</flux:heading>

            @unless ($this->smtpIsActive())
                <flux:callout variant="secondary" icon="information-circle"><flux:callout.text>{{ __('The mail transport is not SMTP (MAIL_MAILER), so these fields do nothing. The transport itself is set in the environment, because other transports can run commands or write files.') }}</flux:callout.text></flux:callout>
            @endunless

            @foreach ([['smtpHost', 'mail.host', 'SMTP host', 'text'], ['smtpPort', 'mail.port', 'SMTP port', 'number'], ['smtpUsername', 'mail.username', 'SMTP username', 'text'], ['fromAddress', 'mail.from_address', 'From address', 'email'], ['fromName', 'mail.from_name', 'From name', 'text']] as [$property, $key, $label, $type])
                <div wire:key="field-{{ $property }}">
                    <flux:input wire:model="{{ $property }}" :type="$type" :label="__($label)" :disabled="$this->isLocked($key) || ! $this->smtpIsActive()" />
                    <x-setting-source :setting="$key" />
                    <flux:error name="{{ $property }}" />
                </div>
            @endforeach

            <div>
                <flux:select wire:model="smtpEncryption" :label="__('Encryption')" :disabled="$this->isLocked('mail.encryption') || ! $this->smtpIsActive()">
                    <flux:select.option value="">{{ __('Default') }}</flux:select.option>
                    <flux:select.option value="tls">{{ __('STARTTLS') }}</flux:select.option>
                    <flux:select.option value="ssl">{{ __('SSL/TLS') }}</flux:select.option>
                    <flux:select.option value="none">{{ __('None') }}</flux:select.option>
                </flux:select>
                <x-setting-source setting="mail.encryption" />
                <flux:error name="smtpEncryption" />
            </div>

            <div class="space-y-2">
                <flux:input wire:model="smtpPassword" type="password" autocomplete="off" :label="__('SMTP password')" :disabled="$this->secretState('mail.password') === 'environment' || ! $this->smtpIsActive()" :placeholder="$this->secretState('mail.password') === 'set' ? __('Set. Type a new value to replace it.') : ''" />
                <flux:text class="text-xs" data-test="smtp-password-state">
                    @if ($this->secretState('mail.password') === 'environment')
                        {{ __('Set by the environment.') }}
                    @elseif ($this->secretState('mail.password') === 'set')
                        {{ __('A password is stored, encrypted. It is never shown again.') }}
                    @else
                        {{ __('No password is set.') }}
                    @endif
                </flux:text>
            </div>
        </div>

        <div class="space-y-4">
            <flux:heading size="lg" level="2">{{ __('Alert rules') }}</flux:heading>
            <flux:text>
                {{ $this->hasChannel() ? __('Each active rule fires at most once an hour for the same subject.') : __('No channel is set, so no alert is sent.') }}
            </flux:text>

            <ul class="divide-y divide-border rounded-panel border border-border">
                @foreach ($this->alertRules() as $rule)
                    <li class="flex items-start justify-between gap-4 px-4 py-3" wire:key="rule-{{ $rule->value }}">
                        <div>
                            <div class="font-medium">{{ __($rule->label()) }}</div>
                            <div class="text-sm text-ink-muted">{{ __($rule->trigger()) }}</div>
                        </div>
                        <flux:switch wire:model="rules.{{ $rule->value }}" :aria-label="__($rule->label())" />
                    </li>
                @endforeach
            </ul>

            <flux:input wire:model="failedAttemptsThreshold" type="number" min="3" :label="__('Failed API attempts that trigger an alert')" />
            <x-setting-source setting="alerts.failed_attempts_threshold" />
            <flux:error name="failedAttemptsThreshold" />

            <flux:input wire:model="ssoRejectionsThreshold" type="number" min="3" :label="__('Rejected single sign-on attempts that trigger an alert')" />
            <x-setting-source setting="alerts.sso_rejections_threshold" />
            <flux:error name="ssoRejectionsThreshold" />
        </div>

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>

    <div class="flex flex-wrap gap-2">
        <flux:button wire:click="sendTestMail" icon="envelope">{{ __('Send test mail') }}</flux:button>
        <flux:button wire:click="sendTestSlack" icon="chat-bubble-left">{{ __('Send test message') }}</flux:button>
    </div>
</section>
