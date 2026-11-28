@props(['status', 'label' => null])

@php
    $map = [
        'active' => ['green', 'check-circle', 'Active'],
        'ok' => ['green', 'check-circle', 'OK'],
        'warning' => ['amber', 'exclamation-triangle', 'Warning'],
        'revoked' => ['red', 'x-circle', 'Revoked'],
        'failed' => ['red', 'x-circle', 'Failed'],
        'error' => ['red', 'x-circle', 'Error'],
        'expired' => ['zinc', 'minus-circle', 'Expired'],
        'unknown' => ['zinc', 'question-mark-circle', 'Unknown'],
    ];
    [$color, $icon, $text] = $map[$status] ?? $map['unknown'];
@endphp

<flux:badge size="sm" :color="$color" :icon="$icon" {{ $attributes }}>{{ $label ?? __($text) }}</flux:badge>