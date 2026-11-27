<?php

use App\Vault\Invitations\AdminInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * The default that a config file falls back to when its environment variable is not set, which is what a fresh
 * install runs with. The test environment sets these variables itself, so the declared default is read from the file.
 */
function declaredDefault(string $file, string $variable): ?string
{
    preg_match("/env\\('{$variable}', '([^']*)'\\)/", (string) file_get_contents(config_path($file.'.php')), $matches);

    return $matches[1] ?? null;
}

it('defaults the cache, the session and the queue to drivers that need no extra service', function () {
    expect(declaredDefault('cache', 'CACHE_STORE'))->toBe('database')
        ->and(declaredDefault('session', 'SESSION_DRIVER'))->toBe('database')
        ->and(declaredDefault('queue', 'QUEUE_CONNECTION'))->toBe('sync');
});

it('sends the admin invitation mail without a queue worker', function () {
    expect(new AdminInvitation('https://vault.example.test/invitations/x', 48))->not->toBeInstanceOf(ShouldQueue::class);

    Queue::fake();
    Notification::fake();

    $this->artisan('vault:install', ['--no-interaction' => true, '--name' => 'First Admin', '--email' => 'first@example.test'])->assertSuccessful();

    Queue::assertNothingPushed();
});

arch('no application class is queued')->expect('App')->not->toImplement(ShouldQueue::class);

arch('the application does not use redis')->expect('App')->not->toUse('Illuminate\Support\Facades\Redis');
