<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

it('does not register the style guide outside the local environment', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    expect(Route::has('styleguide'))->toBeFalse();

    $this->get('/_styleguide')->assertNotFound();
});

it('renders every colour token of the design system in the style guide view', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $html = view('styleguide')->render();

    foreach (['accent', 'accent-soft', 'surface', 'surface-sunken', 'surface-raised', 'surface-hover', 'border', 'border-strong', 'ink', 'ink-muted', 'ink-subtle', 'success', 'warning', 'danger'] as $token) {
        expect($html)->toContain('data-test="token-'.$token.'"');
    }
});

it('defines a light and a dark value for every colour token', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    preg_match('/:root \{(.*?)\}/s', $css, $light);
    preg_match('/\.dark \{(.*?)\}/s', $css, $dark);

    preg_match_all('/--vault-[a-z-]+(?=:)/', $light[1], $lightNames);
    preg_match_all('/--vault-[a-z-]+(?=:)/', $dark[1], $darkNames);

    expect($lightNames[0])->not->toBeEmpty()
        ->and($darkNames[0])->toBe($lightNames[0]);
});

it('self-hosts the fonts with their licences', function () {
    foreach (['instrument-sans-latin-variable.woff2', 'jetbrains-mono-latin-variable.woff2', 'OFL-instrument-sans.txt', 'OFL-jetbrains-mono.txt'] as $file) {
        expect(resource_path('fonts/'.$file))->toBeFile();
    }

    expect(file_get_contents(resource_path('css/app.css')))->not->toContain('fonts.bunny.net')->and(file_get_contents(resource_path('views/partials/head.blade.php')))->not->toContain('@fonts');
});
