<?php

use App\Actions\Vault\DeleteCredential;
use App\Actions\Vault\MoveCredential;
use App\Actions\Vault\UpdateCredential;
use App\Enums\CredentialType;
use App\Exceptions\Vault\HostConflictException;
use App\Livewire\Concerns\HasCredentialForm;
use App\Models\Credential;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Credential')] class extends Component {
    use HasCredentialForm;

    #[Locked]
    public string $credentialId = '';

    public string $targetProjectId = '';

    public function mount(Project $project, Credential $credential): void
    {
        $this->credentialId = $credential->id;
        $this->name = $credential->name;
        $this->type = $credential->type->value;
        $this->host = $credential->host;
        $this->username = (string) $credential->username;
        $this->notes = (string) $credential->notes;
    }

    #[Computed]
    public function credential(): Credential
    {
        return Credential::query()->with('project')->findOrFail($this->credentialId);
    }

    /**
     * The other projects the credential can move to.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function otherProjects(): Collection
    {
        return Project::query()->whereKeyNot($this->credential->project_id)->orderBy('name')->get(['id', 'name', 'slug']);
    }

    public function save(UpdateCredential $updateCredential): void
    {
        $validated = $this->validateCredentialForm(secretRequired: false);
        $newSecret = ($validated['secret'] ?? '') === '' ? null : $validated['secret'];

        try {
            $updateCredential->handle($this->credential, $this->credentialAttributes($validated), $newSecret);
        } catch (HostConflictException $exception) {
            $this->addError('host', $this->hostConflictMessage($exception));

            return;
        }

        session()->flash('flash', __('Credential saved.'));

        $this->redirectRoute('credentials.edit', [$this->credential->project_id, $this->credential], navigate: true);
    }

    public function move(MoveCredential $moveCredential): void
    {
        $this->validate(['targetProjectId' => ['required', 'string', 'exists:projects,id']]);

        try {
            $moveCredential->handle($this->credential, Project::query()->findOrFail($this->targetProjectId));
        } catch (HostConflictException $exception) {
            $this->addError('targetProjectId', $this->hostConflictMessage($exception));

            return;
        }

        session()->flash('flash', __('Credential moved.'));

        $this->redirectRoute('projects.show', $this->targetProjectId, navigate: true);
    }

    public function delete(DeleteCredential $deleteCredential): void
    {
        $projectId = $this->credential->project_id;

        $deleteCredential->handle($this->credential);

        session()->flash('flash', __('Credential deleted.'));

        $this->redirectRoute('projects.show', $projectId, navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl space-y-10">
    <div class="space-y-1">
        <flux:text><flux:link :href="route('projects.show', $this->credential->project)" wire:navigate>{{ $this->credential->project->name }}</flux:link></flux:text>
        <flux:heading size="xl" level="1">{{ $this->credential->name }}</flux:heading>
        <flux:text>
            {{ __('Secret last changed') }}
            <span title="{{ $this->credential->secret_changed_at }}">{{ $this->credential->secret_changed_at->diffForHumans() }}</span>
        </flux:text>
    </div>

    <form wire:submit="save" class="space-y-6">
        <x-credential-fields
            :types="CredentialType::cases()"
            :requires-username="$this->requiresUsername"
            :secret-help="__('Leave empty to keep the current secret.')"
        />

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>

    @if ($this->otherProjects->isNotEmpty())
        <form wire:submit="move" class="space-y-4 rounded-panel border border-border p-5">
            <flux:heading size="lg" level="2">{{ __('Move to another project') }}</flux:heading>
            <flux:text>{{ __('The secret moves with the credential and stays unchanged. The target project must not have a credential for this host yet.') }}</flux:text>

            <flux:select wire:model="targetProjectId" :label="__('Project')">
                <flux:select.option value="">{{ __('Choose a project') }}</flux:select.option>
                @foreach ($this->otherProjects as $project)
                    <flux:select.option :value="$project->id">{{ $project->name }} ({{ $project->slug }})</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="targetProjectId" />

            <flux:button type="submit">{{ __('Move credential') }}</flux:button>
        </form>
    @endif

    <div class="space-y-4 rounded-panel border border-danger/30 p-5">
        <flux:heading size="lg" level="2">{{ __('Delete credential') }}</flux:heading>
        <flux:text>{{ __('Servers that fetch this project stop receiving this credential.') }}</flux:text>

        <flux:modal.trigger name="delete-credential">
            <flux:button variant="danger">{{ __('Delete credential') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:modal name="delete-credential" class="min-w-[22rem] max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this credential?') }}</flux:heading>
                <flux:text class="mt-2">{{ __('The secret is removed for good. This cannot be undone.') }}</flux:text>
                <flux:text class="mt-2">{{ __('Servers using a token of :project lose this credential.', ['project' => $this->credential->project->name]) }}</flux:text>
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button wire:click="delete" variant="danger">{{ __('Delete credential') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</section>
