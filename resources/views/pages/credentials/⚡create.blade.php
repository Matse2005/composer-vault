<?php

use App\Actions\Vault\CreateCredential;
use App\Enums\CredentialType;
use App\Exceptions\Vault\HostConflictException;
use App\Livewire\Concerns\HasCredentialForm;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New credential')] class extends Component {
    use HasCredentialForm;

    public function save(CreateCredential $createCredential): void
    {
        $validated = $this->validateCredentialForm(secretRequired: true);

        try {
            $credential = $createCredential->handle(
                $this->credentialAttributes($validated),
                $validated['secret'],
                array_values($validated['projectIds'] ?? []),
            );
        } catch (HostConflictException $exception) {
            $this->addError('projectIds', $this->hostConflictMessage($exception));

            return;
        }

        session()->flash('flash', __('Credential created.'));

        $this->redirectRoute('credentials.edit', $credential, navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <flux:heading size="xl" level="1">{{ __('New credential') }}</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <x-credential-fields
            :types="CredentialType::cases()"
            :projects="$this->projects"
            :requires-username="$this->requiresUsername"
            :secret-help="__('The secret is stored encrypted and can never be shown again.')"
        />

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary">{{ __('Create credential') }}</flux:button>
            <flux:button variant="ghost" :href="route('credentials.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
