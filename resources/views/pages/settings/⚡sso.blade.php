<?php

use App\Vault\Settings\VaultSettings;
use Flux\Flux;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Single sign-on')] class extends Component {
    private const TEXT_FIELDS = [
        'issuer' => 'Issuer URL',
        'client_id' => 'Client ID',
        'scopes' => 'Scopes',
        'button_label' => 'Button label',
        'groups_claim' => 'Groups claim',
        'allowed_email_domains' => 'Allowed email domains',
        'mfa_acr_values' => 'acr values that count as multi-factor',
    ];

    private const SWITCH_FIELDS = [
        'enabled' => 'Single sign-on is on',
        'auto_provision' => 'Create an admin on the first sign-in',
        'trust_idp_mfa' => 'Trust the multi-factor sign-in of the provider',
        'enforce' => 'Enforce single sign-on: the password and passkey login stop for everyone except break-glass Owners',
    ];

    /** @var array<string, mixed> */
    public array $form = [];

    /** The new client secret. It is never filled from the stored value and is emptied after saving. */
    public string $clientSecret = '';

    public function mount(VaultSettings $settings): void
    {
        foreach (array_keys(self::TEXT_FIELDS) as $field) {
            $this->form[$field] = (string) $settings->get('sso.'.$field);
        }

        foreach (array_keys(self::SWITCH_FIELDS) as $field) {
            $this->form[$field] = (bool) $settings->get('sso.'.$field);
        }
    }

    public function save(VaultSettings $settings): void
    {
        $this->resetErrorBag();
        $values = [];

        foreach (array_merge(self::TEXT_FIELDS, self::SWITCH_FIELDS) as $field => $label) {
            if (! $settings->isFromEnvironment('sso.'.$field)) {
                $values['sso.'.$field] = $this->form[$field] ?? null;
            }
        }

        try {
            $settings->update($values, auth()->user());

            if ($this->clientSecret !== '' && ! $settings->secretIsFromEnvironment('sso.client_secret')) {
                $settings->setSecret('sso.client_secret', $this->clientSecret, auth()->user());
            }
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                $this->addError('form.'.substr($key, 4), $messages[0]);
            }

            return;
        } finally {
            $this->reset('clientSecret');
        }

        Flux::toast(__('Single sign-on settings saved.'));
    }

    public function clearSecret(VaultSettings $settings): void
    {
        $settings->setSecret('sso.client_secret', null, auth()->user());
        Flux::toast(__('Client secret removed.'));
    }

    /**
     * @return array<string, string>
     */
    public function textFields(): array
    {
        return self::TEXT_FIELDS;
    }

    /**
     * @return array<string, string>
     */
    public function switchFields(): array
    {
        return self::SWITCH_FIELDS;
    }

    public function isLocked(string $field): bool
    {
        return app(VaultSettings::class)->isFromEnvironment('sso.'.$field);
    }

    public function secretState(): string
    {
        $settings = app(VaultSettings::class);

        return match (true) {
            $settings->secretIsFromEnvironment('sso.client_secret') => 'environment',
            $settings->hasSecret('sso.client_secret') => 'set',
            default => 'not set',
        };
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Single sign-on') }}</flux:heading>
        <flux:text class="mt-2">
            {{ __('Let people sign in with the OpenID Connect identity provider of your company. Redirect URI to register at the provider:') }}
            <span class="font-mono text-xs">{{ route('sso.callback') }}</span>
        </flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        @foreach ($this->switchFields() as $field => $label)
            <div wire:key="switch-{{ $field }}">
                <flux:switch wire:model="form.{{ $field }}" :label="__($label)" :disabled="$this->isLocked($field)" />
                @if ($this->isLocked($field))
                    <flux:text class="mt-1 text-xs">{{ __('Set by the environment.') }}</flux:text>
                @endif
            </div>
        @endforeach

        @foreach ($this->textFields() as $field => $label)
            <div wire:key="text-{{ $field }}">
                <flux:input wire:model="form.{{ $field }}" :label="__($label)" type="text" :disabled="$this->isLocked($field)" />
                @if ($this->isLocked($field))
                    <flux:text class="mt-1 text-xs">{{ __('Set by the environment.') }}</flux:text>
                @endif
                <flux:error name="form.{{ $field }}" />
            </div>
        @endforeach

        <div class="space-y-2">
            <flux:input wire:model="clientSecret" :label="__('Client secret')" type="password" autocomplete="off" :disabled="$this->secretState() === 'environment'" :placeholder="$this->secretState() === 'set' ? __('Set. Type a new value to replace it.') : ''" />
            <flux:text class="text-xs" data-test="secret-state">
                @if ($this->secretState() === 'environment')
                    {{ __('Set by the environment.') }}
                @elseif ($this->secretState() === 'set')
                    {{ __('A client secret is stored, encrypted. It is never shown again.') }}
                @else
                    {{ __('No client secret is set.') }}
                @endif
            </flux:text>
            @if ($this->secretState() === 'set')
                <flux:button size="sm" wire:click="clearSecret" wire:confirm="{{ __('Remove the client secret? Single sign-on stops working until a new one is set.') }}">{{ __('Remove secret') }}</flux:button>
            @endif
        </div>

        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
    </form>
</section>
