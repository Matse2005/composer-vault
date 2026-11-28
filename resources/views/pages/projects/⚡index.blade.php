<?php

use App\Models\AccessLog;
use App\Models\Project;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Projects')] class extends Component {
    use WithPagination;

    private const SORTABLE = ['name', 'slug', 'updated_at'];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $sortBy = 'name';

    #[Url]
    public string $sortDirection = 'asc';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * Sort by a column, or reverse the direction when it is the column that is already used.
     */
    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        $this->sortDirection = $this->sortBy === $column && $this->sortDirection === 'asc' ? 'desc' : 'asc';
        $this->sortBy = $column;
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        $column = in_array($this->sortBy, self::SORTABLE, true) ? $this->sortBy : 'name';
        $direction = $this->sortDirection === 'desc' ? 'desc' : 'asc';
        $term = '%'.strtr(trim($this->search), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        return Project::query()
            ->withCount(['credentials', 'accessTokens'])
            ->addSelect(['last_fetch_at' => AccessLog::query()->select('created_at')->whereColumn('project_id', 'projects.id')->where('result', 'ok')->latest('created_at')->limit(1)])
            ->when(trim($this->search) !== '', fn ($query) => $query->where(
                fn ($query) => $query->whereRaw("name like ? escape '!'", [$term])->orWhereRaw("slug like ? escape '!'", [$term])
            ))
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->paginate(25);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Projects') }}</flux:heading>

        @can('projects.manage')
        <flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>
            {{ __('New project') }}
        </flux:button>
        @endcan
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search on name or slug')" clearable />

    @if ($this->projects->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            @if (trim($search) !== '')
                <flux:text>{{ __('No projects match your search.') }}</flux:text>
            @else
                <flux:text>{{ __('No projects yet. A project holds the credentials and the server tokens of one application.') }}</flux:text>
                <div class="mt-4">
                    @can('projects.manage')
                    <flux:button variant="primary" icon="plus" :href="route('projects.create')" wire:navigate>
                        {{ __('New project') }}
                    </flux:button>
                    @endcan
                </div>
            @endif
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-start text-sm">
                <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 text-start font-medium">
                            <button type="button" wire:click="sort('name')" class="cursor-pointer font-medium">{{ __('Name') }}</button>
                        </th>
                        <th class="px-4 py-2 text-start font-medium">
                            <button type="button" wire:click="sort('slug')" class="cursor-pointer font-medium">{{ __('Slug') }}</button>
                        </th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Credentials') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Tokens') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Last fetch') }}</th>
                        <th class="px-4 py-2 text-start font-medium">
                            <button type="button" wire:click="sort('updated_at')" class="cursor-pointer font-medium">{{ __('Updated') }}</button>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->projects as $project)
                        <tr wire:key="project-{{ $project->id }}">
                            <td class="px-4 py-2">
                                <flux:link :href="route('projects.show', $project)" wire:navigate>{{ $project->name }}</flux:link>
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $project->slug }}</td>
                            <td class="px-4 py-2">{{ $project->credentials_count }}</td>
                            <td class="px-4 py-2">{{ $project->access_tokens_count }}</td>
                            <td class="px-4 py-2">{{ $project->last_fetch_at ? Illuminate\Support\Carbon::parse($project->last_fetch_at)->diffForHumans() : __('Never') }}</td>
                            <td class="px-4 py-2" title="{{ $project->updated_at }}">{{ $project->updated_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <flux:pagination :paginator="$this->projects" />
    @endif
</section>
