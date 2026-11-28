@props(['label' => null, 'help' => null, 'error' => 'secret', 'keepHint' => false])

<flux:field>
    <flux:label>{{ $label ?? __('Secret') }}</flux:label>
    <flux:input type="password" autocomplete="new-password" spellcheck="false" {{ $attributes->except('value') }} />
    @if ($help || $keepHint)
        <flux:text class="mt-1">{{ $help ?? __('Leave empty to keep the current secret.') }}</flux:text>
    @endif
    <flux:error :name="$error" />
</flux:field>