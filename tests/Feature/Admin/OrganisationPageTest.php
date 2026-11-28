<?php

use App\Models\User;
use App\Vault\Settings\VaultSettings;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());
});

it('shows the stored organisation details', function () {
    app(VaultSettings::class)->update(['organisation.name' => 'Acme Corp'], auth()->user());

    $this->get(route('settings.organisation'))->assertOk();

    Livewire::test('pages::settings.organisation')->assertSet('name', 'Acme Corp');
});

it('saves the organisation details', function () {
    Livewire::test('pages::settings.organisation')
        ->set('name', 'Acme Corp')
        ->set('securityContact', 'security@acme.test')
        ->set('privacyContact', 'privacy@acme.test')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(VaultSettings::class);

    expect($settings->organisationName())->toBe('Acme Corp')
        ->and($settings->securityContact())->toBe('security@acme.test')
        ->and($settings->privacyContact())->toBe('privacy@acme.test');
});

it('shows the validation message of a wrong address', function () {
    Livewire::test('pages::settings.organisation')
        ->set('securityContact', 'nope')
        ->call('save')
        ->assertHasErrors(['securityContact']);
});
