<?php

use App\Rules\ProjectSlug;

function projectSlugPasses(mixed $value): bool
{
    $failed = false;

    (new ProjectSlug)->validate('slug', $value, function () use (&$failed): void {
        $failed = true;
    });

    return ! $failed;
}

it('accepts valid slugs', function (string $slug) {
    expect(projectSlugPasses($slug))->toBeTrue();
})->with([
    'two letters' => 'ab',
    'lowercase word' => 'krijmerieke',
    'with hyphen' => 'my-project',
    'with digits' => 'shop2',
    'hyphen then digit' => 'shop-2-eu',
    'maximum length' => 'a'.str_repeat('b', 63),
]);

it('rejects invalid slugs', function (mixed $slug) {
    expect(projectSlugPasses($slug))->toBeFalse();
})->with([
    'empty' => '',
    'single character' => 'a',
    'starts with a digit' => '1shop',
    'starts with a hyphen' => '-shop',
    'uppercase' => 'Shop',
    'underscore' => 'my_project',
    'space' => 'my project',
    'trailing hyphen' => 'shop-',
    'double hyphen' => 'my--project',
    'too long' => 'a'.str_repeat('b', 64),
    'trailing newline' => "shop\n",
    'not a string' => 12,
    'null' => null,
]);
