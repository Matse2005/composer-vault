<?php

use App\Actions\Vault\CreateCredential;
use App\Enums\CredentialType;
use App\Exceptions\Vault\HostConflictException;
use App\Livewire\Concerns\HasCredentialForm;
use App\Models\Project;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New credential')] class extends Component {
    use HasCredentialForm;

    #[Locked]
    public string $projectId = '';

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
    }

    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    public function save(CreateCredential $createCredential): void
    {
        $validated = $this->validateCredentialForm(secretRequired: true);

        try {
            $credential = $createCredential->handle(
                $this->project(),
                $this->credentialAttributes($validated),
                $validated['secret'],
            );
        } catch (HostConflictException $exception) {
            $this->addError('host', $this->hostConflictMessage($exception));

            return;
        }

        session()->flash('flash', __('Credential created.'));

        $this->redirectRoute('credentials.edit', [$this->projectId, $credential], navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-6">
    <div class="space-y-1">
        <flux:text><flux:link :href="route('projects.show', $this->project())" wire:navigate>{{ $this->project()->name }}</flux:link></flux:text>
        <flux:heading size="xl" level="1">{{ __('New credential') }}</flux:heading>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-credential-fields
            :types="CredentialType::cases()"
            :requires-username="$this->requiresUsername"
            :secret-help="__('The secret is stored encrypted and can never be shown again.')"
        />

        <div class="flex gap-2">
            <flux:button type="submit" variant="primary">{{ __('Create credential') }}</flux:button>
            <flux:button variant="ghost" :href="route('projects.show', $this->project())" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</section>
