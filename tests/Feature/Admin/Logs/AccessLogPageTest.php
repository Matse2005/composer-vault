<?php

use App\Enums\AccessResult;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\Project;
use App\Models\User;
use Database\Factories\AccessTokenFactory;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());
});

it('lists the requests with result, token, project, ip and user agent', function () {
    $project = Project::factory()->create(['slug' => 'webshop']);
    $token = AccessToken::factory()->create(['token_prefix' => 'AbCdEfGh']);
    AccessLog::factory()->create([
        'result' => AccessResult::Ok,
        'access_token_id' => $token->id,
        'project_id' => $project->id,
        'ip' => '203.0.113.7',
        'user_agent' => 'composer/2.8',
    ]);

    Livewire::test('pages::logs.access')
        ->assertSee('ok')
        ->assertSee('cv_AbCdEfGh...')
        ->assertSee(route('tokens.show', $token), false)
        ->assertSee('webshop')
        ->assertSee('203.0.113.7')
        ->assertSee('composer/2.8');
});

it('shows the requested slug when no project was served and a dash when there is no token', function () {
    AccessLog::factory()->create(['result' => AccessResult::UnknownProject, 'requested_slug' => 'does-not-exist', 'project_id' => null, 'access_token_id' => null]);

    Livewire::test('pages::logs.access')
        ->assertSee('unknown_project')
        ->assertSee('does-not-exist');
});

it('truncates a long user agent and keeps the full value in the title', function () {
    $userAgent = str_repeat('x', 120);
    AccessLog::factory()->create(['user_agent' => $userAgent]);

    $html = Livewire::test('pages::logs.access')->html();

    expect($html)->toContain('title="'.$userAgent.'"')
        ->and($html)->not->toContain('>'.$userAgent.'<');
});

it('shows the newest requests first', function () {
    AccessLog::factory()->create(['ip' => '198.51.100.1', 'created_at' => now()->subHour()]);
    AccessLog::factory()->create(['ip' => '198.51.100.2', 'created_at' => now()->subMinute()]);

    Livewire::test('pages::logs.access')->assertSeeInOrder(['198.51.100.2', '198.51.100.1']);
});

it('filters by result', function () {
    AccessLog::factory()->create(['result' => AccessResult::Ok, 'ip' => '198.51.100.1']);
    AccessLog::factory()->create(['result' => AccessResult::IpDenied, 'ip' => '198.51.100.2']);

    Livewire::test('pages::logs.access')
        ->set('result', 'ip_denied')
        ->assertSee('198.51.100.2')
        ->assertDontSee('198.51.100.1')
        ->set('result', '')
        ->assertSee('198.51.100.1')
        ->assertSee('198.51.100.2');
});

it('ignores a result that does not exist', function () {
    AccessLog::factory()->create(['ip' => '198.51.100.1']);

    Livewire::test('pages::logs.access')->set('result', 'nonsense')->assertSee('198.51.100.1');
});

it('filters by token and by project', function () {
    $tokenA = AccessToken::factory()->create();
    $tokenB = AccessToken::factory()->create();
    $projectA = Project::factory()->create();
    $projectB = Project::factory()->create();
    AccessLog::factory()->create(['access_token_id' => $tokenA->id, 'project_id' => $projectA->id, 'ip' => '198.51.100.1']);
    AccessLog::factory()->create(['access_token_id' => $tokenB->id, 'project_id' => $projectB->id, 'ip' => '198.51.100.2']);

    Livewire::test('pages::logs.access')
        ->set('token', $tokenA->id)
        ->assertSee('198.51.100.1')->assertDontSee('198.51.100.2')
        ->set('token', '')
        ->set('project', $projectB->id)
        ->assertSee('198.51.100.2')->assertDontSee('198.51.100.1');
});

it('filters by a date range, including both end days', function () {
    AccessLog::factory()->create(['ip' => '198.51.100.1', 'created_at' => '2026-03-09 23:59:59']);
    AccessLog::factory()->create(['ip' => '198.51.100.2', 'created_at' => '2026-03-10 00:00:00']);
    AccessLog::factory()->create(['ip' => '198.51.100.3', 'created_at' => '2026-03-12 23:59:59']);
    AccessLog::factory()->create(['ip' => '198.51.100.4', 'created_at' => '2026-03-13 00:00:00']);

    Livewire::test('pages::logs.access')
        ->set('from', '2026-03-10')
        ->set('to', '2026-03-12')
        ->assertDontSee('198.51.100.1')
        ->assertSee('198.51.100.2')
        ->assertSee('198.51.100.3')
        ->assertDontSee('198.51.100.4');
});

it('ignores a date that is not a date', function () {
    AccessLog::factory()->create(['ip' => '198.51.100.1']);

    Livewire::test('pages::logs.access')->set('from', 'yesterday-ish')->set('to', '2026-99-99')->assertSee('198.51.100.1');
});

it('shows 25 requests a page', function () {
    foreach (range(1, 30) as $number) {
        AccessLog::factory()->create(['ip' => sprintf('198.51.100.%d', $number), 'created_at' => now()->subMinutes(31 - $number)]);
    }

    Livewire::test('pages::logs.access')
        ->assertSee('198.51.100.30')
        ->assertDontSee('198.51.100.5')
        ->call('gotoPage', 2)
        ->assertSee('198.51.100.5');
});

it('shows an empty state', function () {
    Livewire::test('pages::logs.access')->assertSee('No requests');
});

it('never renders a token hash or a plain token', function () {
    $token = AccessToken::factory()->withPlainToken()->create();
    AccessLog::factory()->create(['access_token_id' => $token->id]);

    $component = Livewire::test('pages::logs.access');

    foreach ([$component->html(), json_encode($component->snapshot)] as $output) {
        expect($output)->not->toContain($token->token_hash)
            ->not->toContain(AccessTokenFactory::plainTokenFor($token));
    }
});

it('renders the page', function () {
    $this->get(route('logs.access'))->assertOk()->assertSeeLivewire('pages::logs.access');
});
