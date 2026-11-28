@props(['value', 'label' => null, 'multiline' => false])

<div {{ $attributes->class('space-y-1') }} x-data="{ copied: false, copy() { navigator.clipboard.writeText($refs.value.textContent.trim()); this.copied = true; setTimeout(() => this.copied = false, 1500) } }">
    @if ($label)
        <flux:text class="text-caption">{{ $label }}</flux:text>
    @endif
    <div class="flex items-start gap-2">
        @if ($multiline)
            <pre x-ref="value" class="min-w-0 flex-1 overflow-x-auto rounded-card bg-surface-sunken p-3 font-mono text-small text-ink" data-test="copy-value">{{ $value }}</pre>
        @else
            <code x-ref="value" class="min-w-0 flex-1 overflow-x-auto rounded-card bg-surface-sunken px-3 py-2 font-mono text-small text-ink" data-test="copy-value">{{ $value }}</code>
        @endif
        <flux:button size="sm" variant="ghost" icon="clipboard" x-on:click="copy()" type="button">
            <span x-show="!copied">{{ __('Copy') }}</span>
            <span x-show="copied" x-cloak role="status">{{ __('Copied') }}</span>
        </flux:button>
    </div>
</div>