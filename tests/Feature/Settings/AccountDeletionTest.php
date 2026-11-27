<?php

use App\Models\User;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Livewire;

it('no longer offers self-service account deletion', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('Delete account')
        ->assertDontSee('delete-user');
});

it('has no component that deletes the account', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);

    expect(fn () => Livewire::test('pages::settings.delete-user-modal')->call('deleteUser'))
        ->toThrow(ComponentNotFoundException::class);

    expect($user->fresh())->not->toBeNull();
});
