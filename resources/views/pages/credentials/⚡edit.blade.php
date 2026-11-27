<?php

use App\Actions\Vault\DeleteCredential;
use App\Actions\Vault\UpdateCredential;
use App\Enums\CredentialType;
use App\Exceptions\Vault\HostConflictException;
use App\Livewire\Concerns\HasCredentialForm;
use App\Models\Credential;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Credential')] class extends Component {
    use HasCredentialForm;

    #[Locked]
    public string $credentialId = '';

    public function mount(Credential $credential): void
    {
        $this->credentialId = $credential->id;
        $this->name = $credential->name;
        $this->type = $credential->type->value;
        $this->host = $credential->host;
        $this->username = (string) $credential->username;
        $this->notes = (string) $credential->notes;
        $this->projectIds = $credential->projects()->pluck('projects.id')->all();
    }

    #[Computed]
    public function credential(): Credential
    {
        return Credential::query()->findOrFail($this->credentialId);
    }

    /**
     * The projects that lose the credential when it is deleted.
     *
     * @return Collection<int, App\Models\Project>
     */
    #[Computed]
    public function linkedProjects(): Collection
    {
        return $this->credential->projects()->orderBy('name')->get(['projects.id', 'projects.name', 'projects.slug']);
    }

    public function save(UpdateCredential $updateCredential): void
    {
        $validated = $this->validateCredentialForm(secretRequired: false);
        $newSecret = ($validated['secret'] ?? '') === '' ? null : $validated['secret'];

        try {
            $updateCredential->handle(
                $this->credential,
                $this->credentialAttributes($validated),
                $newSecret,
                array_values($validated['projectIds'] ?? []),
            );
        } catch (HostConflictException $exception) {
            $field = $exception->host === $this->credential->host ? 'projectIds' : 'host';
            $this->addError($field, $this->hostConflictMessage($exception));

            return;
        }

        session()->flash('flash', __('Credential saved.'));

        $this->redirectRoute('credentials.edit', $this->credential, navigate: true);
    }

    public function delete(DeleteCredential $deleteCredential): void
    {
        $deleteCredential->handle($this->credential);

        session()->flash('flash', __('Credential deleted.'));

        $this->redirectRoute('credentials.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-10">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ $this->credential->name }}</flux:heading>
        <flux:text>
            {{ __('Secret last changed') }}
            <span title="{{ $this->credential->secret_changed_at }}">{{ $this->credential->secret_changed_at->diffForHumans() }}</span>
        </flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-credential-fields
            :types="CredentialType::cases()"
            :projects="$this->projects"
            :requires-username="$this->requiresUsername"
            :secret-help="__('Leave empty to keep the current secret.')"
        />

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>

    <div class="space-y-4 rounded-xl border border-red-200 p-5 dark:border-red-900">
        <flux:heading size="lg" level="2">{{ __('Delete credential') }}</flux:heading>
        <flux:text>{{ __('Servers that fetch this credential through a project stop receiving it.') }}</flux:text>

        <flux:modal.trigger name="delete-credential">
            <flux:button variant="danger">{{ __('Delete credential') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:modal name="delete-credential" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this credential?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The secret is removed for good. This cannot be undone.') }}</flux:text>
            </div>

            @if ($this->linkedProjects->isEmpty())
                <flux:text>{{ __('No project uses this credential.') }}</flux:text>
            @else
                <div class="space-y-2">
                    <flux:text>{{ __('These projects lose this credential:') }}</flux:text>
                    <ul class="list-disc ps-5 text-sm">
                        @foreach ($this->linkedProjects as $project)
                            <li wire:key="linked-project-{{ $project->id }}">{{ $project->name }} ({{ $project->slug }})</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button wire:click="delete" variant="danger">{{ __('Delete credential') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
