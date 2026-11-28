@props(['name', 'title', 'text' => null, 'confirm' => null, 'action' => 'delete', 'button' => 'Confirm', 'model' => 'confirmation', 'error' => null])

<flux:modal :name="$name" class="min-w-88 max-w-md">
    <form wire:submit="{{ $action }}" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ $title }}</flux:heading>
            @if ($text)
                <flux:text class="mt-2">{{ $text }}</flux:text>
            @endif
            {{ $slot }}
        </div>

        @if ($confirm !== null)
            <flux:field>
                <flux:label>{{ __('Type :value to confirm', ['value' => $confirm]) }}</flux:label>
                <flux:input wire:model="{{ $model }}" type="text" autocomplete="off" />
                <flux:error :name="$error ?? $model" />
            </flux:field>
        @endif

        <div class="flex justify-end gap-2">
            <flux:modal.close><flux:button variant="ghost" type="button">{{ __('Cancel') }}</flux:button></flux:modal.close>
            <flux:button type="submit" variant="danger">{{ $button }}</flux:button>
        </div>
    </form>
</flux:modal>