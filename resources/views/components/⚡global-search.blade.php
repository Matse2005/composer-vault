<?php

use App\Vault\Search\GlobalSearch;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public string $term = '';

    /**
     * @return list<array{project: array{id: string, name: string, slug: string}, matches: list<array{type: string, label: string, detail: string|null, url: string}>}>
     */
    #[Computed]
    public function results(): array
    {
        return app(GlobalSearch::class)->search(auth()->user(), $this->term);
    }
}; ?>

<div class="relative px-2" x-data x-on:keydown.escape="$wire.term = ''">
    <flux:input wire:model.live.debounce.250ms="term" type="search" icon="magnifying-glass" size="sm" :placeholder="__('Search')" :aria-label="__('Search projects, credentials and tokens')" autocomplete="off" data-test="global-search" />

    @if (mb_strlen(trim($term)) >= App\Vault\Search\GlobalSearch::MIN_LENGTH)
        <div class="mt-2 space-y-3 rounded-card border border-border bg-surface-raised p-2 text-small" data-test="search-results" role="region" aria-live="polite" aria-label="{{ __('Search results') }}">
            @forelse ($this->results as $group)
                <div wire:key="search-{{ $group['project']['id'] }}">
                    <div class="px-2 pb-1 text-caption font-medium text-ink-muted">{{ $group['project']['name'] }}</div>
                    <ul>
                        @foreach ($group['matches'] as $match)
                            <li wire:key="match-{{ $group['project']['id'] }}-{{ $match['type'] }}-{{ $loop->index }}">
                                <a href="{{ $match['url'] }}" wire:navigate class="flex items-baseline justify-between gap-2 rounded-control px-2 py-1 text-ink hover:bg-surface-hover focus:bg-surface-hover focus:outline-2 focus:outline-accent">
                                    <span class="truncate">{{ $match['label'] }}</span>
                                    <span class="shrink-0 text-caption text-ink-muted">{{ __(ucfirst($match['type'])) }}@if ($match['detail']), <span class="font-mono">{{ $match['detail'] }}</span>@endif</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <div class="px-2 py-1 text-ink-muted">{{ __('Nothing found.') }}</div>
            @endforelse
        </div>
    @endif
</div>