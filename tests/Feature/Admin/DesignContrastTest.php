<?php

/**
 * Parse the design token values from the stylesheet for one mode.
 *
 * @return array<string, string>
 */
function designTokens(string $selector): array
{
    $css = file_get_contents(resource_path('css/app.css'));

    preg_match('/'.preg_quote($selector, '/').' \{(.*?)\}/s', $css, $block);
    preg_match_all('/--vault-([a-z-]+):\s*(#[0-9a-fA-F]{6});/', $block[1], $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn (array $match): array => [$match[1] => $match[2]])->all();
}

function relativeLuminance(string $hex): float
{
    $channels = array_map(function (string $channel): float {
        $value = hexdec($channel) / 255;

        return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, str_split(ltrim($hex, '#'), 2));

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function contrastRatio(string $first, string $second): float
{
    $a = relativeLuminance($first);
    $b = relativeLuminance($second);

    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
}

it('keeps every text token pair at WCAG AA contrast in light and dark', function (string $selector) {
    $tokens = designTokens($selector);

    $pairs = [
        ['ink', 'surface'], ['ink', 'surface-sunken'], ['ink', 'surface-raised'], ['ink', 'surface-hover'],
        ['ink-muted', 'surface'], ['ink-muted', 'surface-sunken'], ['ink-muted', 'surface-raised'], ['ink-muted', 'surface-hover'],
        ['ink-subtle', 'surface'], ['ink-subtle', 'surface-sunken'],
        ['accent-content', 'surface'], ['accent-content', 'surface-sunken'], ['accent-content', 'accent-soft'],
        ['accent-foreground', 'accent'],
        ['success', 'surface'], ['success', 'success-soft'],
        ['warning', 'surface'], ['warning', 'warning-soft'],
        ['danger', 'surface'], ['danger', 'danger-soft'],
    ];

    $failures = [];

    foreach ($pairs as [$foreground, $background]) {
        $ratio = contrastRatio($tokens[$foreground], $tokens[$background]);

        if ($ratio < 4.5) {
            $failures[] = sprintf('%s on %s is %.2f', $foreground, $background, $ratio);
        }
    }

    expect($failures)->toBe([]);
})->with([':root', '.dark']);
