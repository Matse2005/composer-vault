<?php

use App\Models\AccessLog;
use Illuminate\Console\Scheduling\Schedule;

it('prunes only rows older than the retention', function () {
    config(['vault.access_log_retention_days' => 90]);
    $old = AccessLog::factory()->create(['created_at' => now()->subDays(91)]);
    $oldest = AccessLog::factory()->create(['created_at' => now()->subYear()]);
    $boundary = AccessLog::factory()->create(['created_at' => now()->subDays(89)]);
    $recent = AccessLog::factory()->create(['created_at' => now()->subMinute()]);

    $this->artisan('model:prune', ['--model' => AccessLog::class])->assertSuccessful();

    expect(AccessLog::query()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$boundary->id, $recent->id])->sort()->values()->all())
        ->and(AccessLog::query()->whereKey([$old->id, $oldest->id])->count())->toBe(0);
});

it('uses the configured retention', function () {
    config(['vault.access_log_retention_days' => 7]);
    AccessLog::factory()->create(['created_at' => now()->subDays(8)]);
    $kept = AccessLog::factory()->create(['created_at' => now()->subDays(6)]);

    $this->artisan('model:prune', ['--model' => AccessLog::class])->assertSuccessful();

    expect(AccessLog::query()->pluck('id')->all())->toBe([$kept->id]);
});

it('defaults to a retention of 90 days', function () {
    expect(config('vault.access_log_retention_days'))->toBe(90);
});

it('schedules the pruning daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'model:prune') && str_contains($event->command, 'AccessLog'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});
