@props(['credential', 'project', 'canManage' => false, 'rotationDays' => null])

<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-3 rounded-panel border border-border bg-surface px-4 py-3') }}>
    <div class="flex items-center gap-3">
        <x-vault.credential-type-icon :type="$credential->type" />
        <div>
            @if ($canManage)
                <flux:link :href="route('credentials.edit', [$project, $credential])" wire:navigate class="font-medium">{{ $credential->name }}</flux:link>
            @else
                <span class="font-medium text-ink">{{ $credential->name }}</span>
            @endif
            <div class="text-caption text-ink-muted"><span class="font-mono">{{ $credential->host }}</span>, {{ $credential->type->label() }}</div>
        </div>
    </div>
    <div class="flex items-center gap-2 text-caption text-ink-muted">
        <span title="{{ $credential->secret_changed_at }}">{{ __('Secret changed :time', ['time' => $credential->secret_changed_at->diffForHumans()]) }}</span>
        @if ($rotationDays !== null && $credential->secret_changed_at->lt(now()->subDays($rotationDays)))
            <x-vault.status-badge status="warning" :label="__('Rotation due')" />
        @endif
    </div>
</div>