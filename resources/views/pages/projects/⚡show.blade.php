<?php

use App\Actions\Vault\DeleteProject;
use App\Actions\Vault\LinkProjectToCredentials;
use App\Actions\Vault\UpdateProject;
use App\Exceptions\Vault\HostConflictException;
use App\Models\Credential;
use App\Models\Project;
use App\Rules\ProjectSlug;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Project')] class extends Component {
    #[Locked]
    public string $projectId = '';

    public string $name = '';

    public string $slug = '';

    public string $notes = '';

    /** @var array<int, string> */
    public array $credentialIds = [];

    public string $deleteConfirmation = '';

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->name = $project->name;
        $this->slug = $project->slug;
        $this->notes = (string) $project->notes;
        $this->credentialIds = $project->credentials()->pluck('credentials.id')->all();
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    /**
     * @return Collection<int, Credential>
     */
    #[Computed]
    public function credentials(): Collection
    {
        return Credential::query()->orderBy('name')->get(['id', 'name', 'type', 'host']);
    }

    #[Computed]
    public function slugChanged(): bool
    {
        return $this->slug !== $this->project->slug;
    }

    public function saveDetails(UpdateProject $updateProject): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', new ProjectSlug, Rule::unique('projects', 'slug')->ignore($this->projectId)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $updateProject->handle($this->project, [
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'notes' => $validated['notes'] === '' ? null : $validated['notes'],
        ]);

        unset($this->project);
        session()->flash('flash', __('Project saved.'));
        $this->redirectRoute('projects.show', $this->project, navigate: true);
    }

    public function saveCredentials(LinkProjectToCredentials $linkProjectToCredentials): void
    {
        $this->validate([
            'credentialIds' => ['array'],
            'credentialIds.*' => ['string', Rule::exists('credentials', 'id')],
        ]);

        try {
            $linkProjectToCredentials->handle($this->project, array_values($this->credentialIds));
        } catch (HostConflictException $exception) {
            $this->addError('credentialIds', __('A project can use one credential per host, and :host is selected twice.', ['host' => $exception->host]));

            return;
        }

        session()->flash('flash', __('Credentials saved.'));
        $this->redirectRoute('projects.show', $this->project, navigate: true);
    }

    public function delete(DeleteProject $deleteProject): void
    {
        $this->validate(['deleteConfirmation' => ['required', Rule::in([$this->project->slug])]], [
            'deleteConfirmation.in' => __('Type the slug of the project to confirm.'),
        ]);

        $deleteProject->handle($this->project);

        session()->flash('flash', __('Project deleted.'));
        $this->redirectRoute('projects.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-3xl space-y-10">
    <div class="space-y-1">
        <flux:heading size="xl" level="1">{{ $this->project->name }}</flux:heading>
        <flux:text class="font-mono text-xs">{{ $this->project->slug }}</flux:text>
    </div>

    <form wire:submit="saveDetails" class="space-y-6">
        <flux:heading size="lg" level="2">{{ __('Details') }}</flux:heading>

        <flux:field>
            <flux:label>{{ __('Name') }}</flux:label>
            <flux:input wire:model="name" type="text" required />
            <flux:error name="name" />
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Slug') }}</flux:label>
            <flux:input wire:model.live.debounce.300ms="slug" type="text" required />
            <flux:error name="slug" />
        </flux:field>

        @if ($this->slugChanged)
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Changing the slug breaks existing setups') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Every composer.json that uses the slug :slug has to be updated, or its servers lose access to this project.', ['slug' => $this->project->slug]) }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <flux:field>
            <flux:label>{{ __('Notes') }}</flux:label>
            <flux:textarea wire:model="notes" rows="4" />
            <flux:error name="notes" />
        </flux:field>

        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
    </form>

    <form wire:submit="saveCredentials" class="space-y-4">
        <flux:heading size="lg" level="2">{{ __('Credentials') }}</flux:heading>
        <flux:text>{{ __('A project can use one credential per host.') }}</flux:text>

        @forelse ($this->credentials as $credential)
            <flux:checkbox
                wire:key="credential-{{ $credential->id }}"
                wire:model="credentialIds"
                :value="$credential->id"
                :label="$credential->name.' ('.$credential->type->label().', '.$credential->host.')'"
            />
        @empty
            <flux:text>{{ __('There are no credentials yet.') }}</flux:text>
        @endforelse

        <flux:error name="credentialIds" />
        <flux:error name="credentialIds.0" />

        <flux:button type="submit" variant="primary">{{ __('Save credentials') }}</flux:button>
    </form>

    <div class="space-y-4">
        <flux:heading size="lg" level="2">{{ __('Tokens') }}</flux:heading>

        @php($tokens = $this->project->accessTokens()->orderBy('name')->get())

        @if ($tokens->isEmpty())
            <flux:text>{{ __('No token can fetch this project yet.') }}</flux:text>
        @else
            <ul class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($tokens as $token)
                    <li wire:key="token-{{ $token->id }}" class="flex items-center justify-between px-4 py-2">
                        <flux:link :href="route('tokens.show', $token)" wire:navigate>{{ $token->name }}</flux:link>
                        <span class="text-sm">
                            @if ($token->isRevoked())
                                {{ __('Revoked') }}
                            @elseif ($token->isExpired())
                                {{ __('Expired') }}
                            @else
                                {{ __('Active') }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="space-y-4 rounded-xl border border-red-200 p-5 dark:border-red-900">
        <flux:heading size="lg" level="2">{{ __('Delete project') }}</flux:heading>
        <flux:text>
            {{ __('Tokens that are linked to this project lose access to this project. The credentials stay in the vault.') }}
        </flux:text>

        <flux:modal.trigger name="delete-project">
            <flux:button variant="danger">{{ __('Delete project') }}</flux:button>
        </flux:modal.trigger>
    </div>

    <flux:modal name="delete-project" class="min-w-[22rem] max-w-md">
        <form wire:submit="delete" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete this project?') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Tokens that are linked to this project lose access to this project, and the credentials stay in the vault. This cannot be undone.') }}
                </flux:text>
            </div>

            <flux:field>
                <flux:label>{{ __('Type :slug to confirm', ['slug' => $this->project->slug]) }}</flux:label>
                <flux:input wire:model="deleteConfirmation" type="text" autocomplete="off" />
                <flux:error name="deleteConfirmation" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger">{{ __('Delete project') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
