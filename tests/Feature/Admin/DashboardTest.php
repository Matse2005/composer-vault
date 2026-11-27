<?php

use App\Enums\AccessResult;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());
});

it('counts projects, credentials and active tokens', function () {
    Project::factory()->count(3)->create();
    Credential::factory()->count(2)->create();
    AccessToken::factory()->count(2)->create();
    AccessToken::factory()->revoked()->create();
    AccessToken::factory()->expired()->create();

    Livewire::test('pages::dashboard')
        ->assertSet('projectCount', 3)
        ->assertSet('credentialCount', 2)
        ->assertSet('activeTokenCount', 2);
});

it('counts failed api requests of the last 24 hours only', function () {
    AccessLog::factory()->create(['result' => AccessResult::Ok]);
    AccessLog::factory()->create(['result' => AccessResult::UnknownToken]);
    AccessLog::factory()->create(['result' => AccessResult::IpDenied]);
    AccessLog::factory()->create(['result' => AccessResult::RateLimited, 'created_at' => now()->subHours(23)]);
    AccessLog::factory()->create(['result' => AccessResult::Revoked, 'created_at' => now()->subHours(25)]);

    Livewire::test('pages::dashboard')->assertSet('failedRequestCount', 3);
});

it('lists the most recent failed requests and leaves out successful ones', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    $token = AccessToken::factory()->create(['token_prefix' => 'AbCdEfGh']);
    AccessLog::factory()->create(['result' => AccessResult::Ok, 'ip' => '198.51.100.1', 'project_id' => $project->id]);
    AccessLog::factory()->create([
        'result' => AccessResult::ProjectDenied,
        'ip' => '203.0.113.9',
        'project_id' => null,
        'requested_slug' => 'other-project',
        'access_token_id' => $token->id,
    ]);
    AccessLog::factory()->create(['result' => AccessResult::IpDenied, 'ip' => '203.0.113.10', 'project_id' => $project->id]);

    Livewire::test('pages::dashboard')
        ->assertSee('project_denied')
        ->assertSee('ip_denied')
        ->assertSee('other-project')
        ->assertSee('shop')
        ->assertSee('AbCdEfGh')
        ->assertSee('203.0.113.9')
        ->assertSee('203.0.113.10')
        ->assertDontSee('198.51.100.1');
});

it('shows at most the ten most recent failures, newest first', function () {
    foreach (range(1, 12) as $minutesAgo) {
        AccessLog::factory()->create([
            'result' => AccessResult::UnknownToken,
            'ip' => "203.0.113.{$minutesAgo}",
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    $component = Livewire::test('pages::dashboard');

    expect($component->get('recentFailures'))->toHaveCount(10)
        ->and($component->get('recentFailures')->first()->ip)->toBe('203.0.113.1')
        ->and($component->get('recentFailures')->last()->ip)->toBe('203.0.113.10');
});

it('shows empty counters and an empty failure list on a fresh vault', function () {
    Livewire::test('pages::dashboard')
        ->assertSet('projectCount', 0)
        ->assertSet('credentialCount', 0)
        ->assertSet('activeTokenCount', 0)
        ->assertSet('failedRequestCount', 0)
        ->assertSee('No failed API requests');
});

it('renders the dashboard page for an admin with a second factor', function () {
    $this->get(route('dashboard'))->assertOk()->assertSeeLivewire('pages::dashboard');
});
