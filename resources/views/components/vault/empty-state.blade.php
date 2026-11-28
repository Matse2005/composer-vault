@props(['title', 'text' => null, 'icon' => 'inbox'])

<div {{ $attributes->class('rounded-panel border border-dashed border-border-strong p-10 text-center') }}>
    <flux:icon :name="$icon" class="mx-auto size-6 text-ink-subtle" />
    <flux:heading class="mt-2">{{ $title }}</flux:heading>
    @if ($text)
        <flux:text class="mt-1">{{ $text }}</flux:text>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-4">{{ $slot }}</div>
    @endif
</div>