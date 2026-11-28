<?php

use App\Actions\Vault\DeleteProject;
use App\Actions\Vault\UpdateProject;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Project;
use App\Rules\ProjectSlug;
use App\Vault\Settings\VaultSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
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

    public string $deleteConfirmation = '';

    public function mount(Project $project): void
    {
        $this->projectId = $project->id;
        $this->name = $project->name;
        $this->slug = $project->slug;
        $this->notes = (string) $project->notes;
    }

    #[Computed]
    public function project(): Project
    {
        return Project::query()->withCount(['credentials', 'accessTokens'])->findOrFail($this->projectId);
    }

    /**
     * The credentials of this project and nothing else.
     *
     * @return Collection<int, Credential>
     */
    #[Computed]
    public function credentials(): Collection
    {
        return Credential::query()->where('project_id', $this->projectId)->orderBy('name')->get(['id', 'project_id', 'name', 'type', 'host', 'secret_changed_at']);
    }

    /**
     * The server tokens of this project and nothing else.
     *
     * @return Collection<int, AccessToken>
     */
    #[Computed]
    public function tokens(): Collection
    {
        return AccessToken::query()->where('project_id', $this->projectId)->orderBy('name')->get();
    }

    #[Computed]
    public function lastFetch(): ?Carbon
    {
        $time = AccessLog::query()->where('project_id', $this->projectId)->where('result', 'ok')->max('created_at');

        return $time === null ? null : Carbon::parse($time);
    }

    /**
     * @return Collection<int, AccessLog>
     */
    #[Computed]
    public function recentAccess(): Collection
    {
        return AccessLog::query()->where('project_id', $this->projectId)->latest('created_at')->latest('id')->limit(20)->get();
    }

    /**
     * The audit entries about this project, its credentials and its tokens.
     *
     * @return Collection<int, AuditEntry>
     */
    #[Computed]
    public function recentChanges(): Collection
    {
        $ids = [$this->projectId, ...$this->credentials->pluck('id')->all(), ...$this->tokens->pluck('id')->all()];

        return AuditEntry::query()->whereIn('subject_id', $ids)->orderByDesc('sequence')->limit(20)->get();
    }

    public function rotationDays(): ?int
    {
        return app(VaultSettings::class)->credentialRotationDays();
    }

    #[Computed]
    public function slugChanged(): bool
    {
        return $this->slug !== $this->project->slug;
    }

    public function appUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
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

<section class="w-full max-w-5xl space-y-10">
    <div class="space-y-4 rounded-xl bg-zinc-100 p-6 dark:bg-zinc-800/60">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('projects.index')" wire:navigate>{{ __('Projects') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $this->project->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-2">
                <flux:heading size="xl" level="1">{{ $this->project->name }}</flux:heading>
                <code class="rounded bg-white px-2 py-1 font-mono text-xs dark:bg-zinc-900" data-test="project-slug">{{ $this->project->slug }}</code>
                @if ($this->project->notes)
                    <flux:text>{{ $this->project->notes }}</flux:text>
                @endif
                <flux:text class="text-xs" data-test="project-counts">
                    {{ trans_choice(':count credential|:count credentials', $this->project->credentials_count) }},
                    {{ trans_choice(':count server token|:count server tokens', $this->project->access_tokens_count) }},
                    @if ($this->lastFetch)
                        {{ __('last fetch :time', ['time' => $this->lastFetch->diffForHumans()]) }}
                    @else
                        {{ __('never fetched') }}
                    @endif
                </flux:text>
            </div>

            <div class="flex flex-wrap gap-2">
                @can('credentials.manage')
                    <flux:button variant="primary" icon="plus" :href="route('credentials.create', $this->project)" wire:navigate>{{ __('Add credential') }}</flux:button>
                @endcan
                @can('tokens.manage')
                    <flux:button icon="ticket" :href="route('tokens.create', $this->project)" wire:navigate>{{ __('New server token') }}</flux:button>
                @endcan
                <flux:modal.trigger name="use-in-composer">
                    <flux:button icon="code-bracket">{{ __('Use in composer.json') }}</flux:button>
                </flux:modal.trigger>
            </div>
        </div>
    </div>

    @can('credentials.view')
        <div class="space-y-3" data-test="credentials">
            <flux:heading size="lg" level="2">{{ __('Credentials') }}</flux:heading>

            @forelse ($this->credentials as $credential)
                <div wire:key="credential-{{ $credential->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <div>
                        @can('credentials.manage')
                            <flux:link :href="route('credentials.edit', [$this->project, $credential])" wire:navigate class="font-medium">{{ $credential->name }}</flux:link>
                        @else
                            <span class="font-medium">{{ $credential->name }}</span>
                        @endcan
                        <div class="text-xs text-zinc-600 dark:text-zinc-400"><span class="font-mono">{{ $credential->host }}</span>, {{ $credential->type->label() }}</div>
                    </div>
                    <div class="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                        <span title="{{ $credential->secret_changed_at }}">{{ __('Secret changed :time', ['time' => $credential->secret_changed_at->diffForHumans()]) }}</span>
                        @if ($this->rotationDays() !== null && $credential->secret_changed_at->lt(now()->subDays($this->rotationDays())))
                            <flux:badge size="sm" color="amber" icon="exclamation-triangle">{{ __('Rotation due') }}</flux:badge>
                        @endif
                    </div>
                </div>
            @empty
                <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600" data-test="credentials-empty">
                    <flux:heading>{{ __('No credentials yet') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('A credential is a key for a host such as repo.magento.com. Servers that use a token of this project receive it.') }}</flux:text>
                    @can('credentials.manage')
                        <flux:button class="mt-4" variant="primary" icon="plus" :href="route('credentials.create', $this->project)" wire:navigate>{{ __('Add credential') }}</flux:button>
                    @endcan
                </div>
            @endforelse
        </div>
    @endcan

    @can('tokens.view')
        <div class="space-y-3" data-test="tokens">
            <flux:heading size="lg" level="2">{{ __('Server tokens') }}</flux:heading>

            @forelse ($this->tokens as $token)
                <div wire:key="token-{{ $token->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <div>
                        @can('tokens.manage')
                            <flux:link :href="route('tokens.show', [$this->project, $token])" wire:navigate class="font-medium">{{ $token->name }}</flux:link>
                        @else
                            <span class="font-medium">{{ $token->name }}</span>
                        @endcan
                        <div class="text-xs text-zinc-600 dark:text-zinc-400">{{ $token->last_used_at ? __('used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('never used') }}</div>
                    </div>
                    <span class="text-sm">
                        @if ($token->isRevoked())
                            <flux:badge size="sm" color="red" icon="x-circle">{{ __('Revoked') }}</flux:badge>
                        @elseif ($token->isExpired())
                            <flux:badge size="sm" icon="minus-circle">{{ __('Expired') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="green" icon="check-circle">{{ __('Active') }}</flux:badge>
                        @endif
                    </span>
                </div>
            @empty
                <flux:text>{{ __('No server token can fetch this project yet.') }}</flux:text>
            @endforelse
        </div>
    @endcan

    @can('logs.view')
        <div class="space-y-3" data-test="activity">
            <flux:heading size="lg" level="2">{{ __('Activity') }}</flux:heading>
            <div class="grid gap-6 md:grid-cols-2">
                <div class="space-y-1">
                    <flux:heading level="3">{{ __('Recent fetches') }}</flux:heading>
                    @forelse ($this->recentAccess as $log)
                        <div wire:key="access-{{ $log->id }}" class="flex justify-between text-sm"><span class="font-mono text-xs">{{ $log->result->value }}, {{ $log->ip }}</span><span title="{{ $log->created_at }}">{{ $log->created_at?->diffForHumans() }}</span></div>
                    @empty
                        <flux:text>{{ __('Nothing was fetched yet.') }}</flux:text>
                    @endforelse
                </div>
                <div class="space-y-1">
                    <flux:heading level="3">{{ __('Recent changes') }}</flux:heading>
                    @forelse ($this->recentChanges as $entry)
                        <div wire:key="change-{{ $entry->sequence }}" class="flex justify-between text-sm"><span class="font-mono text-xs">{{ $entry->event }}</span><span title="{{ $entry->created_at }}">{{ $entry->created_at?->diffForHumans() }}</span></div>
                    @empty
                        <flux:text>{{ __('No change yet.') }}</flux:text>
                    @endforelse
                </div>
            </div>
        </div>
    @endcan

    @can('projects.manage')
        <form wire:submit="saveDetails" class="space-y-6">
            <flux:heading size="lg" level="2">{{ __('Project settings') }}</flux:heading>

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

        <div class="space-y-4 rounded-xl border border-red-200 p-5 dark:border-red-900">
            <flux:heading size="lg" level="2">{{ __('Delete project') }}</flux:heading>
            <flux:text>{{ __('The credentials and the server tokens of this project are deleted with it.') }}</flux:text>

            <flux:modal.trigger name="delete-project">
                <flux:button variant="danger">{{ __('Delete project') }}</flux:button>
            </flux:modal.trigger>
        </div>

        <flux:modal name="delete-project" class="min-w-[22rem] max-w-md">
            <form wire:submit="delete" class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Delete this project?') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('This cannot be undone. These are deleted with the project:') }}</flux:text>
                    <ul class="mt-2 list-disc ps-5 text-sm" data-test="delete-list">
                        @foreach ($this->credentials as $credential)
                            <li wire:key="delete-credential-{{ $credential->id }}">{{ __('Credential :name', ['name' => $credential->name]) }} (<span class="font-mono text-xs">{{ $credential->host }}</span>)</li>
                        @endforeach
                        @foreach ($this->tokens as $token)
                            <li wire:key="delete-token-{{ $token->id }}">{{ __('Server token :name', ['name' => $token->name]) }}</li>
                        @endforeach
                        @if ($this->credentials->isEmpty() && $this->tokens->isEmpty())
                            <li>{{ __('Nothing else: the project is empty.') }}</li>
                        @endif
                    </ul>
                </div>

                <flux:field>
                    <flux:label>{{ __('Type :slug to confirm', ['slug' => $this->project->slug]) }}</flux:label>
                    <flux:input wire:model="deleteConfirmation" type="text" autocomplete="off" />
                    <flux:error name="deleteConfirmation" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger">{{ __('Delete project') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    <flux:modal name="use-in-composer" class="min-w-[22rem] max-w-xl">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Use in composer.json') }}</flux:heading>
            <flux:text>{{ __('Give the server a token of this project in COMPOSER_VAULT_TOKEN, then fetch the credentials:') }}</flux:text>
            <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 font-mono text-xs dark:bg-zinc-900" data-test="curl-line">export COMPOSER_AUTH=$(curl -fsS -H "Authorization: Bearer $COMPOSER_VAULT_TOKEN" {{ $this->appUrl() }}/api/v1/projects/{{ $this->project->slug }}/composer-auth)</pre>
        </div>
    </flux:modal>
</section>
