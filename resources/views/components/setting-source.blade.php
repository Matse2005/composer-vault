@props(['setting'])

@php
    $settings = app(\App\Vault\Settings\VaultSettings::class);
    $definition = \App\Vault\Settings\SettingsRegistry::has($setting) ? \App\Vault\Settings\SettingsRegistry::get($setting) : null;
    $source = $settings->source($setting);
    $default = $definition?->default;
@endphp

<flux:text {{ $attributes->class('mt-1 text-xs') }} data-test="source-{{ $setting }}">
    @if ($source === 'environment')
        {{ __('Set by the environment') }}@if ($definition?->envVariable) ({{ $definition->envVariable }})@endif. {{ __('Change it there. The field is locked.') }}
    @elseif ($source === 'dashboard')
        {{ __('Source: dashboard.') }}
    @else
        {{ __('Source: default.') }}
    @endif
    @if (! $definition?->isSecret && $default !== null && $default !== '')
        {{ __('Default: :value.', ['value' => is_bool($default) ? ($default ? __('on') : __('off')) : $default]) }}
    @endif
</flux:text>