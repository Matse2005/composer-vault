@props(['type'])

@php
    $value = $type instanceof \App\Enums\CredentialType ? $type->value : (string) $type;
    $icon = match ($value) {
        'http-basic' => 'key',
        'bearer' => 'shield-check',
        'github-oauth' => 'code-bracket',
        'gitlab-token' => 'cube',
        default => 'key',
    };
@endphp

<flux:icon :name="$icon" {{ $attributes->class('size-5 text-ink-muted') }} aria-hidden="true" />