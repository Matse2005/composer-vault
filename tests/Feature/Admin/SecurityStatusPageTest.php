<?php

use App\Models\User;
use App\Vault\Health\HealthRunner;
use Livewire\Livewire;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Facades\Health;

beforeEach(function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());
    Health::clearChecks();
});

function statusCheck(string $name, string $status, string $summary = 'Summary text', string $fix = ''): Check
{
    return new class($name, $status, $summary, $fix) extends Check
    {
        public function __construct(private readonly string $checkName, private readonly string $outcome, private readonly string $summary, private readonly string $fix)
        {
            parent::__construct();
            $this->name($this->checkName);
        }

        public function run(): Result
        {
            $result = Result::make();
            $result = match ($this->outcome) {
                'failed' => $result->failed('Failing.'),
                'warning' => $result->warning('Warning.'),
                default => $result->ok(),
            };

            return $result->shortSummary($this->summary)->meta($this->fix === '' ? [] : ['fix' => $this->fix]);
        }
    };
}

it('shows an empty state before any check has run', function () {
    Livewire::test('pages::security-status')->assertSee('No checks have run yet');
});

it('shows the latest stored results with their time', function () {
    Health::checks([statusCheck('alpha', 'ok', 'Alpha is fine'), statusCheck('beta', 'failed', 'Beta is broken', 'Repair beta')]);
    app(HealthRunner::class)->runFresh();

    $component = Livewire::test('pages::security-status')
        ->assertSee('Alpha is fine')
        ->assertSee('Beta is broken')
        ->assertSee('Repair beta')
        ->assertSee('Last run');

    expect($component->html())->toContain('data-test="finished-at"');
});

it('runs all checks again with the button', function () {
    Health::checks([statusCheck('alpha', 'ok', 'Alpha is fine')]);

    Livewire::test('pages::security-status')
        ->assertSee('No checks have run yet')
        ->call('runChecks')
        ->assertSee('Alpha is fine')
        ->assertDontSee('No checks have run yet');

    $this->assertDatabaseCount('health_check_result_history_items', 1);
});

it('refreshes stale results when the button runs again', function () {
    Health::checks([statusCheck('alpha', 'failed', 'Alpha is broken')]);
    $component = Livewire::test('pages::security-status')->call('runChecks')->assertSee('Alpha is broken');

    Health::clearChecks();
    Health::checks([statusCheck('alpha', 'ok', 'Alpha is repaired')]);
    $this->travel(2)->seconds();

    $component->call('runChecks')->assertSee('Alpha is repaired')->assertDontSee('Alpha is broken');
});

it('summarises the latest run on the dashboard', function () {
    $dashboard = fn () => Livewire::test('pages::dashboard');

    $dashboard()->assertSee('No health results yet, see Security status');

    Health::checks([statusCheck('alpha', 'ok')]);
    app(HealthRunner::class)->runFresh();
    $dashboard()->assertSee('All checks pass');

    Health::clearChecks();
    Health::checks([statusCheck('alpha', 'failed'), statusCheck('beta', 'warning'), statusCheck('gamma', 'ok')]);
    $this->travel(2)->seconds();
    app(HealthRunner::class)->runFresh();
    $dashboard()->assertSee('2 problems, see Security status');
});

it('is not reachable for a guest', function () {
    auth()->logout();

    $this->get(route('security-status'))->assertRedirect(route('login'));
});

it('renders the page', function () {
    $this->get(route('security-status'))->assertOk()->assertSeeLivewire('pages::security-status');
});
