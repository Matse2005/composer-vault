@props([
    'value',
    'line' => null,
    'title',
    'note' => null,
    'closeMethod' => 'closeTokenModal',
    'closeLabel' => null,
])

{{-- A value that is shown once. The modal cannot be closed by clicking outside, by Escape or by a close button: the
     only way out is the button below, which makes the component forget the value. --}}
<flux:modal name="one-time-secret" :dismissible="false" :closable="false" :escapable="false" class="min-w-96 max-w-xl" data-test="one-time-secret">
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">{{ $title }}</flux:heading>
            <flux:text class="mt-2">{{ $note ?? __('This token is shown once. Store it now.') }}</flux:text>
        </div>

        <div class="flex items-center gap-2" x-data="copyToClipboard" data-text="{{ $value }}">
            <flux:input readonly :value="$value" :aria-label="$title" class="font-mono" data-test="one-time-secret-value" />
            <flux:button type="button" icon="clipboard-document" x-on:click="copy()" :aria-label="__('Copy')">
                <span x-show="!copied">{{ __('Copy') }}</span>
                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </flux:button>
        </div>

        @if ($line)
            <div class="space-y-1">
                <flux:text class="text-sm">{{ __('Ready to paste into the .env file of the server:') }}</flux:text>
                <flux:input readonly :value="$line" :aria-label="__('Line for the .env file')" class="font-mono" data-test="one-time-secret-line" />
            </div>
        @endif

        <div class="flex justify-end">
            <flux:button variant="primary" wire:click="{{ $closeMethod }}">{{ $closeLabel ?? __('I have stored it') }}</flux:button>
        </div>
    </div>
</flux:modal>
