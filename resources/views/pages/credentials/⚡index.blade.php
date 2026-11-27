<?php

use App\Enums\CredentialType;
use App\Models\Credential;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Credentials')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Credential>
     */
    #[Computed]
    public function credentials(): LengthAwarePaginator
    {
        $term = '%'.strtr(trim($this->search), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        $type = CredentialType::tryFrom($this->type);

        return Credential::query()
            ->withCount('projects')
            ->when($type !== null, fn ($query) => $query->where('type', $type->value))
            ->when(trim($this->search) !== '', fn ($query) => $query->where(
                fn ($query) => $query->whereRaw("name like ? escape '!'", [$term])->orWhereRaw("host like ? escape '!'", [$term])
            ))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25, ['id', 'name', 'type', 'host', 'username', 'secret_changed_at']);
    }
}; ?>

<section class="w-full space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <flux:heading size="xl" level="1">{{ __('Credentials') }}</flux:heading>

        <flux:button variant="primary" icon="plus" :href="route('credentials.create')" wire:navigate>
            {{ __('New credential') }}
        </flux:button>
    </div>

    <div class="flex flex-wrap gap-4">
        <div class="min-w-64 flex-1">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search on name or host')" clearable />
        </div>
        <flux:select wire:model.live="type" class="max-w-56">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach (CredentialType::cases() as $case)
                <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    @if ($this->credentials->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            @if (trim($search) !== '' || $type !== '')
                <flux:text>{{ __('No credentials match your filters.') }}</flux:text>
            @else
                <flux:text>{{ __('No credentials yet. A credential holds the key for one host, for example repo.magento.com.') }}</flux:text>
                <div class="mt-4">
                    <flux:button variant="primary" icon="plus" :href="route('credentials.create')" wire:navigate>
                        {{ __('New credential') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full text-start text-sm">
                <thead class="bg-zinc-50 text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Type') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Host') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Username') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Projects') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ __('Secret changed') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->credentials as $credential)
                        <tr wire:key="credential-{{ $credential->id }}">
                            <td class="px-4 py-2">
                                <flux:link :href="route('credentials.edit', $credential)" wire:navigate>{{ $credential->name }}</flux:link>
                            </td>
                            <td class="px-4 py-2">{{ $credential->type->label() }}</td>
                            <td class="px-4 py-2 font-mono text-xs">{{ $credential->host }}</td>
                            <td class="px-4 py-2">{{ $credential->username ?? '-' }}</td>
                            <td class="px-4 py-2">{{ $credential->projects_count }}</td>
                            <td class="px-4 py-2" title="{{ $credential->secret_changed_at }}">{{ $credential->secret_changed_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <flux:pagination :paginator="$this->credentials" />
    @endif
</section>
