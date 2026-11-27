<?php

use App\Models\User;

it('does not let a deactivated admin log in with the right password', function () {
    $user = User::factory()->create();
    $user->forceFill(['deactivated_at' => now()])->save();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('still lets an active admin log in', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

it('gives a deactivated admin the same error as a wrong password', function () {
    $deactivated = User::factory()->create();
    $deactivated->forceFill(['deactivated_at' => now()])->save();
    $active = User::factory()->create();

    $this->post(route('login.store'), ['email' => $deactivated->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->flushSession();
    $this->post(route('login.store'), ['email' => $active->email, 'password' => 'wrong-password'])
        ->assertSessionHasErrors(['email' => trans('auth.failed')]);
});

it('ends an open session of a deactivated admin on its next request', function () {
    $user = User::factory()->withTwoFactor()->create();
    $this->actingAs($user);
    $this->get(route('dashboard'))->assertOk();

    $user->forceFill(['deactivated_at' => now()])->save();

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('ends an open session of a deactivated admin on a Livewire page request too', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->forceFill(['deactivated_at' => now()])->save();
    $this->actingAs($user);

    $this->get(route('projects.index'))->assertRedirect(route('login'));
    $this->get(route('security.edit'))->assertRedirect(route('login'));
});

it('lets a deactivated admin log out', function () {
    $user = User::factory()->withTwoFactor()->create();
    $user->forceFill(['deactivated_at' => now()])->save();
    $this->actingAs($user);

    $this->post(route('logout'))->assertRedirect();

    $this->assertGuest();
});
