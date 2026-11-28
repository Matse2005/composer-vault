@props(['label', 'value', 'hint' => null, 'href' => null, 'valueTest' => 'stat-value'])

<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class('block rounded-panel border border-border bg-surface p-5') }}>
    <flux:text>{{ $label }}</flux:text>
    <flux:heading size="xl" data-test="{{ $valueTest }}">{{ $value }}</flux:heading>
    @if ($hint)
        <flux:text class="text-caption">{{ $hint }}</flux:text>
    @endif
</{{ $href ? 'a' : 'div' }}>