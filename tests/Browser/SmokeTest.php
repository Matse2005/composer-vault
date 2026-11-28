<?php

use App\Models\AccessToken;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use App\Vault\Tokens\TokenIssuer;
use PragmaRX\Google2FA\Google2FA;

it('lets an admin set up a project, credential and token and fetch the composer auth', function () {
    $secret = app(Google2FA::class)->generateSecretKey();
    $admin = User::factory()->withTwoFactor()->create(['email' => 'admin@example.test']);
    $admin->forceFill(['two_factor_secret' => encrypt($secret)])->save();

    $page = visit('/login');

    $page->type('email', 'admin@example.test')
        ->type('password', 'password')
        ->click('Log in')
        ->assertPathIs('/two-factor-challenge');

    foreach (str_split(app(Google2FA::class)->getCurrentOtp($secret)) as $position => $digit) {
        $page->type('[data-flux-otp] > *:nth-child('.($position + 1).') input', $digit);
    }

    $page->click('Continue')
        ->waitForText('Dashboard')
        ->assertPathIs('/dashboard');

    $page->navigate('/projects/create')
        ->type('[wire\:model\.live\.debounce\.300ms="name"]', 'Webshop')
        ->wait(1)
        ->press('form[wire\:submit] button[type="submit"]')
        ->waitForText('Project created.');

    $project = Project::query()->sole();

    $page->navigate('/projects/'.$project->id.'/credentials/create')
        ->type('[wire\:model="name"]', 'Magento repo')
        ->type('[wire\:model="host"]', 'repo.example.com')
        ->type('[wire\:model="username"]', 'repo-user')
        ->type('[wire\:model="secret"]', 'repo-secret-value');
    $page->press('form[wire\:submit] button[type="submit"]')
        ->waitForText('Credential created.');

    expect(Credential::query()->sole()->host)->toBe('repo.example.com');

    $page->navigate('/projects/'.$project->id.'/tokens/create')
        ->type('[wire\:model="name"]', 'ci-token');
    $page->press('form[wire\:submit] button[type="submit"]')
        ->waitForText('This token is shown once');

    $plain = $page->value('[data-test="one-time-secret-value"] input, input[data-test="one-time-secret-value"]');

    expect($plain)->toStartWith('cv_')->and(AccessToken::query()->sole()->token_prefix)->toBe(TokenIssuer::prefix($plain));

    $response = $page->script(
        "fetch('/api/v1/projects/{$project->slug}/composer-auth', {headers: {Authorization: 'Bearer {$plain}'}}).then(r => r.json())"
    );

    expect(json_encode($response))->toContain('repo.example.com');

    foreach ([[375, 800], [1280, 800]] as [$width, $height]) {
        $page->resize($width, $height);

        foreach (['/dashboard', '/projects', '/projects/'.$project->id, '/logs/access', '/logs/audit', '/admins', '/security-status'] as $path) {
            $page->navigate($path)->assertPathIs($path);

            expect($page->script('document.documentElement.scrollWidth <= window.innerWidth + 1'))->toBeTrue("Horizontal scroll on {$path} at {$width}px");
        }
    }

    $page->assertNoJavaScriptErrors()->assertNoConsoleLogs();
});
