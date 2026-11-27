<?php

use App\Models\User;

it('redirects the root to the dashboard for admins and to the login page for guests', function () {
    $this->get('/')->assertRedirect(route('login'));

    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->get('/')->assertRedirect(route('dashboard'));
});

it('renders the navigation groups and links for an admin with a second factor', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Composer Vault')
        ->assertSee('Vault')
        ->assertSee('Activity')
        ->assertSee('Administration')
        ->assertSee(route('dashboard'), false)
        ->assertSee(route('projects.index'), false)
        ->assertSee(route('credentials.index'), false)
        ->assertSee(route('tokens.index'), false)
        ->assertSee(route('logs.access'), false)
        ->assertSee(route('logs.audit'), false)
        ->assertSee(route('admins.index'), false);
});

it('links to the project repository and not to the starter kit', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('https://github.com/Matse2005/composer-vault', false)
        ->assertDontSee('laravel/livewire-starter-kit', false)
        ->assertDontSee('laravel.com/docs/starter-kits', false);
});

it('does not show a security status link before it exists', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->get(route('dashboard'))->assertOk()->assertDontSee('Security status');
});

it('shows the flash message of the previous action', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->withSession(['flash' => 'Project created.'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Project created.');
});

it('shows no flash message when there is none', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->get(route('dashboard'))->assertOk()->assertDontSee('data-test="flash-message"', false);
});

it('keeps the admin pages behind the second factor', function () {
    $this->actingAs(User::factory()->create());

    // Each ticket adds the routes of its own pages to this list.
    foreach (['dashboard', 'projects.index', 'projects.create', 'credentials.index', 'credentials.create', 'tokens.index', 'tokens.create', 'logs.access', 'logs.audit', 'admins.index'] as $name) {
        $this->get(route($name))->assertRedirect(route('security.edit'));
    }
});
