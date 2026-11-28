<?php

use App\Enums\AuditAction;
use App\Models\AccessToken;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditLogger;
use App\Vault\Compliance\SecurityEventStream;
use App\Vault\Health\SecurityLogChannelCheck;
use App\Vault\Settings\VaultSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Monolog\Handler\TestHandler;
use Tests\Support\ThrowingLogHandler;

/**
 * The JSON lines written to the test channel.
 *
 * @return list<array<string, mixed>>
 */
function streamLines(): array
{
    return collect(Log::channel('security')->getLogger()->getHandlers()[0]->getRecords())
        ->map(fn ($record) => json_decode($record->message, true))
        ->all();
}

beforeEach(function () {
    config([
        'logging.channels.security' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'vault.security_log_channel' => 'security',
    ]);
});

it('writes every audit entry as one json line with the stream fields only', function () {
    $actor = User::factory()->create(['name' => 'Alice Actor', 'email' => 'alice@example.test']);
    $project = Project::factory()->create(['name' => 'Secret Shop']);

    $entry = app(AuditLogger::class)->record(AuditAction::ProjectUpdated, $project, ['notes' => 'Contact Jane', 'name' => 'Secret Shop'], $actor);

    $lines = streamLines();

    expect($lines)->toHaveCount(1)
        ->and(array_keys($lines[0]))->toBe(['type', 'time', 'event', 'sequence', 'actor_id', 'subject_type', 'subject_id', 'ip'])
        ->and($lines[0])->toMatchArray(['type' => 'audit', 'event' => 'project_updated', 'sequence' => $entry->sequence, 'actor_id' => $actor->id, 'subject_type' => Project::class, 'subject_id' => $project->id])
        ->and($lines[0]['time'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/');

    $raw = json_encode($lines);

    foreach (['Alice Actor', 'alice@example.test', 'Secret Shop', 'Contact Jane'] as $value) {
        expect($raw)->not->toContain($value);
    }
});

it('writes failed api accesses and not successful ones', function () {
    $project = Project::factory()->create(['slug' => 'shop']);
    [$token, $plain] = tokenWithPlain(AccessToken::factory()->for($project));

    $this->getJson('/api/v1/projects/shop/composer-auth', ['Authorization' => 'Bearer '.$plain]);
    expect(streamLines())->toBe([]);

    $this->getJson('/api/v1/projects/shop/composer-auth', ['Authorization' => 'Bearer cv_'.str_repeat('x', 40)]);
    $this->getJson('/api/v1/projects/other/composer-auth', ['Authorization' => 'Bearer '.$plain]);

    $lines = streamLines();

    expect($lines)->toHaveCount(2)
        ->and(array_keys($lines[0]))->toBe(['type', 'time', 'result', 'ip', 'token_prefix', 'project_slug'])
        ->and($lines[0]['type'])->toBe('access')
        ->and($lines[0]['result'])->toBe('unknown_token')
        ->and($lines[1]['project_slug'])->toBeNull()
        ->and($lines[1]['token_prefix'])->toBe($token->token_prefix)
        ->and(json_encode($lines))->not->toContain($plain)->not->toContain('Bearer');
});

it('writes nothing while the channel is not set', function () {
    config(['vault.security_log_channel' => null]);

    app(AuditLogger::class)->record(AuditAction::ProjectUpdated, Project::factory()->create());

    expect(Log::channel('security')->getLogger()->getHandlers()[0]->getRecords())->toBe([]);
});

it('writes a line only after the transaction committed', function () {
    $project = Project::factory()->create();

    try {
        DB::transaction(function () use ($project) {
            app(AuditLogger::class)->record(AuditAction::ProjectUpdated, $project);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(streamLines())->toBe([]);
});

it('does not fail the request or the audit write when the channel is down', function () {
    config(['logging.channels.security' => ['driver' => 'monolog', 'handler' => ThrowingLogHandler::class]]);

    $entry = app(AuditLogger::class)->record(AuditAction::ProjectUpdated, Project::factory()->create());

    expect($entry->exists)->toBeTrue()->and(SecurityEventStream::hasFailedRecently())->toBeTrue();

    [$token, $plain] = tokenWithPlain();
    $this->getJson('/api/v1/projects/none/composer-auth', ['Authorization' => 'Bearer '.$plain])->assertNotFound();
});

it('reports the channel through the health check', function () {
    $check = fn (): string => app(SecurityLogChannelCheck::class)->run()->status->value;

    config(['vault.security_log_channel' => null]);
    expect($check())->toBe('ok');

    config(['vault.security_log_channel' => 'security']);
    Cache::forget(SecurityEventStream::FAILURE_CACHE_KEY);
    expect($check())->toBe('ok');

    Cache::put(SecurityEventStream::FAILURE_CACHE_KEY, time(), 60);
    expect($check())->toBe('warning');

    Cache::forget(SecurityEventStream::FAILURE_CACHE_KEY);
    config(['vault.security_log_channel' => 'missing']);
    expect($check())->toBe('warning');
});

it('sets the channel on the access policy page and refuses an unknown one', function () {
    config(['vault.security_log_channel' => null]);
    $this->actingAs(User::factory()->withTwoFactor()->create());

    Livewire::test('pages::settings.access')->set('securityLogChannel', 'security')->call('save')->assertHasNoErrors();

    expect(app(VaultSettings::class)->securityLogChannel())->toBe('security');

    Livewire::test('pages::settings.access')->set('securityLogChannel', 'nope')->call('save')->assertHasErrors('securityLogChannel');
});
