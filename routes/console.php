<?php

use App\Models\AccessLog;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\Commands\ScheduleCheckHeartbeatCommand;
use Spatie\Health\Models\HealthCheckResultHistoryItem;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| The order matters: the daily prune of the access log and of old health results, the health checks every five
| minutes, and the heartbeat last, as the package documents, so the schedule check proves the whole schedule ran.
|
*/

Schedule::command('model:prune', ['--model' => [AccessLog::class, HealthCheckResultHistoryItem::class]])->daily();

Schedule::command(RunHealthChecksCommand::class)->everyFiveMinutes();

Schedule::command(ScheduleCheckHeartbeatCommand::class)->everyMinute();
