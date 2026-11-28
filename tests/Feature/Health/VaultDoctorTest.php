<?php

use App\Models\User;
use App\Vault\Health\SlackWebhookChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;
use Spatie\Health\Facades\Health;
use Spatie\Health\Notifications\CheckFailedNotification;

/**
 * A check with a fixed outcome, for testing what runs the checks.
 */
function fixedCheck(string $name, string $status, string $fix = ''): Check
{
    return new class($name, $status, $fix) extends Check
    {
        public function __construct(private readonly string $checkName, private readonly string $outcome, private readonly string $fix)
        {
            parent::__construct();
            $this->name($this->checkName);
        }

        public function run(): Result
        {
            $result = Result::make();

            $result = match ($this->outcome) {
                'failed' => $result->failed('It failed.'),
                'warning' => $result->warning('It warns.'),
                default => $result->ok(),
            };

            return $result->shortSummary("{$this->checkName} summary")->meta($this->fix === '' ? [] : ['fix' => $this->fix]);
        }
    };
}

function registerChecks(string ...$statuses): void
{
    Health::clearChecks();
    Health::checks(array_map(fn (string $status, int $position): Check => fixedCheck("check{$position}", $status, $status === 'ok' ? '' : "Fix {$position}"), $statuses, array_keys($statuses)));
}

it('exits 0 when every check passes', function () {
    registerChecks('ok', 'ok');

    $this->artisan('vault:doctor')->assertExitCode(0);
});

it('exits 1 when a check fails', function () {
    registerChecks('ok', 'failed');

    $this->artisan('vault:doctor')->assertExitCode(1);
    $this->artisan('vault:doctor', ['--strict' => true])->assertExitCode(1);
});

it('exits 0 on a warning unless strict', function () {
    registerChecks('ok', 'warning');

    $this->artisan('vault:doctor')->assertExitCode(0);
    $this->artisan('vault:doctor', ['--strict' => true])->assertExitCode(1);
});

it('prints a table with status, check, summary and fix', function () {
    registerChecks('failed');

    $this->artisan('vault:doctor')
        ->expectsTable(['Status', 'Check', 'Summary', 'Fix'], [['failed', 'Check0', 'check0 summary', 'Fix 0']])
        ->assertExitCode(1);
});

it('prints the json shape', function () {
    registerChecks('ok', 'warning');

    Artisan::call('vault:doctor', ['--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($json))->toBe(['status', 'settings', 'checks'])
        ->and($json['status'])->toBe('warn')
        ->and($json['checks'])->toBe([
            ['name' => 'check0', 'status' => 'ok', 'summary' => 'check0 summary', 'fix' => ''],
            ['name' => 'check1', 'status' => 'warning', 'summary' => 'check1 summary', 'fix' => 'Fix 1'],
        ]);
});

it('reports fail in the json when a check failed', function () {
    registerChecks('failed', 'warning');

    Artisan::call('vault:doctor', ['--json' => true]);

    expect(json_decode(Artisan::output(), true)['status'])->toBe('fail');
});

it('stores the results of a fresh run', function () {
    registerChecks('ok', 'failed');

    $this->artisan('vault:doctor');

    $this->assertDatabaseCount('health_check_result_history_items', 2);
    $this->assertDatabaseHas('health_check_result_history_items', ['check_name' => 'check1', 'status' => 'failed', 'short_summary' => 'check1 summary']);
});

it('marks a check that throws as crashed', function () {
    Health::clearChecks();
    Health::checks([new class extends Check
    {
        public function run(): Result
        {
            throw new RuntimeException('boom with secret-detail');
        }
    }]);

    Artisan::call('vault:doctor', ['--json' => true]);
    $output = Artisan::output();

    expect(json_decode($output, true)['status'])->toBe('fail')->and($output)->not->toContain('secret-detail');
});

it('registers no health route', function () {
    foreach (app('router')->getRoutes() as $route) {
        expect($route->uri())->not->toContain('health')->not->toContain('oh-dear');
    }

    $this->get('/health')->assertNotFound();
    $this->get('/oh-dear-health-check-results')->assertNotFound();
});

it('schedules the prune, the health run and the heartbeat in that order', function () {
    $commands = collect(Schedule::events())->map(fn ($event): string => $event->command)->values();

    $prune = $commands->search(fn (string $command): bool => str_contains($command, 'model:prune'));
    $health = $commands->search(fn (string $command): bool => str_contains($command, 'health:check'));
    $heartbeat = $commands->search(fn (string $command): bool => str_contains($command, 'health:schedule-check-heartbeat'));

    expect($prune)->not->toBeFalse()->and($health)->toBeGreaterThan($prune)->and($heartbeat)->toBeGreaterThan($health)
        ->and($heartbeat)->toBe($commands->count() - 1);

    $events = collect(Schedule::events());
    expect($events->firstWhere(fn ($event) => str_contains($event->command, 'health:check'))->expression)->toBe('*/5 * * * *')
        ->and($events->firstWhere(fn ($event) => str_contains($event->command, 'heartbeat'))->expression)->toBe('* * * * *');
});

it('does not need a queue for the health notification', function () {
    expect(new CheckFailedNotification([]))->not->toBeInstanceOf(ShouldQueue::class);
    expect(class_exists(RunHealthChecksCommand::class) && class_exists(ScheduleCheckHeartbeatCommand::class))->toBeTrue();
});

it('sends one notification for a failing check when mail is configured', function () {
    Notification::fake();
    config([
        'health.notifications.enabled' => true,
        'health.notifications.mail.to' => 'ops@example.test',
        'health.notifications.notifications' => [CheckFailedNotification::class => ['mail']],
    ]);
    registerChecks('ok', 'failed');

    Artisan::call('health:check');
    Artisan::call('health:check');

    Notification::assertSentTimes(CheckFailedNotification::class, 1);
});

it('uses a notification channel only when its setting is configured', function () {
    $channels = fn (): array => config('health.notifications.notifications')[CheckFailedNotification::class];

    expect($channels())->toBe([])->and(config('health.notifications.enabled'))->toBeFalse();

    foreach (['VAULT_HEALTH_NOTIFY_MAIL' => 'ops@example.test', 'VAULT_HEALTH_NOTIFY_SLACK_WEBHOOK' => 'https://hooks.slack.test/T/B/X'] as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    try {
        $this->refreshApplication();

        expect($channels())->toBe(['mail', SlackWebhookChannel::class])
            ->and(config('health.notifications.enabled'))->toBeTrue()
            ->and(config('health.notifications.mail.to'))->toBe('ops@example.test')
            ->and(config('health.notifications.slack.webhook_url'))->toBe('https://hooks.slack.test/T/B/X');
    } finally {
        foreach (['VAULT_HEALTH_NOTIFY_MAIL', 'VAULT_HEALTH_NOTIFY_SLACK_WEBHOOK'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
});

it('sends nothing when no channel is configured', function () {
    Notification::fake();
    registerChecks('failed');

    Artisan::call('health:check');

    Notification::assertNothingSent();
});

it('posts a failing check to the slack webhook with names and summaries only', function () {
    Http::fake();
    Health::clearChecks();
    config([
        'health.notifications.enabled' => true,
        'health.notifications.slack.webhook_url' => 'https://hooks.slack.test/T000/B000/XXXX',
        'health.notifications.notifications' => [CheckFailedNotification::class => [SlackWebhookChannel::class]],
    ]);
    Health::checks([fixedCheck('check0', 'failed', 'Fix 0')]);

    Artisan::call('health:check');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://hooks.slack.test/T000/B000/XXXX'
        && str_contains($request['text'], 'Check0')
        && str_contains($request['text'], 'check0 summary'));
});

it('does not run the checks for a guest or leak results to one', function () {
    registerChecks('failed');
    User::factory()->create();

    $this->get(route('security-status'))->assertRedirect(route('login'));
});
