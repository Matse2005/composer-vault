<?php

use App\Enums\AccessResult;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has the access results as snake case values', function () {
    expect(array_column(AccessResult::cases(), 'value'))->toBe([
        'ok',
        'unknown_token',
        'revoked',
        'expired',
        'ip_denied',
        'unknown_project',
        'project_denied',
        'rate_limited',
    ]);
});

it('stores a log row with an auto increment key and a created at date only', function () {
    $log = AccessLog::factory()->create(['result' => AccessResult::IpDenied]);

    expect($log->refresh()->getKey())->toBeInt()
        ->and($log->result)->toBe(AccessResult::IpDenied)
        ->and($log->created_at)->not->toBeNull()
        ->and(Schema::hasColumn('access_logs', 'updated_at'))->toBeFalse()
        ->and($log->getAttributes())->not->toHaveKey('updated_at');
});

it('allows a log row without a token or a project', function () {
    $log = AccessLog::factory()->unknownToken()->create();

    expect($log->refresh()->access_token_id)->toBeNull()
        ->and($log->project_id)->toBeNull()
        ->and($log->result)->toBe(AccessResult::UnknownToken);
});

it('keeps the log row and clears the references when the token or project is deleted', function () {
    $token = AccessToken::factory()->create();
    $project = Project::factory()->create();
    $log = AccessLog::factory()->create([
        'access_token_id' => $token->id,
        'project_id' => $project->id,
        'requested_slug' => $project->slug,
    ]);

    $token->delete();
    $project->delete();

    expect($log->refresh()->access_token_id)->toBeNull()
        ->and($log->project_id)->toBeNull()
        ->and($log->requested_slug)->toBe($project->slug);
});

it('creates the audit log head with the genesis row', function () {
    $rows = DB::table('audit_log_head')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe(1)
        ->and($rows->first()->sequence)->toBe(0)
        ->and($rows->first()->last_hash)->toBe(str_repeat('0', 64));
});
