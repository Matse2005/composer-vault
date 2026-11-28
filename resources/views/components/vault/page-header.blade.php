@props(['title', 'description' => null])

<div {{ $attributes->class('flex flex-wrap items-start justify-between gap-4') }}>
    <div class="space-y-1">
        @isset($breadcrumbs)
            <div>{{ $breadcrumbs }}</div>
        @endisset
        <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
        @if ($description)
            <flux:text>{{ $description }}</flux:text>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap gap-2">{{ $actions }}</div>
    @endisset
</div>