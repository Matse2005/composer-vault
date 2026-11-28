<?php

use App\Actions\Vault\DeleteAccessToken;
use App\Actions\Vault\UpdateAccessToken;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

it('updates name, allowed ips and expiry, keeps the project and audits exactly the changes', function () {
    $project = Project::factory()->create();
    $token = AccessToken::factory()->for($project)->withAllowedIps(['203.0.113.7'])->create(['name' => 'old-name']);
    $expiresAt = CarbonImmutable::parse('2027-02-01 23:59:59', 'UTC');

    app(UpdateAccessToken::class)->handle($token, 'new-name', ['10.0.0.0/8', '2001:db8::/32'], $expiresAt);

    $fresh = $token->fresh();

    expect($fresh->name)->toBe('new-name')
        ->and($fresh->allowed_ips)->toBe(['10.0.0.0/8', '2001:db8::/32'])
        ->and($fresh->expires_at->toDateTimeString())->toBe('2027-02-01 23:59:59')
        ->and($fresh->project_id)->toBe($project->id);

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('token_updated')
        ->and($entry->subject_id)->toBe($token->id)
        ->and($entry->properties->toArray())->toEqual([
            'name' => ['old' => 'old-name', 'new' => 'new-name'],
            'allowed_ips' => ['old' => ['203.0.113.7'], 'new' => ['10.0.0.0/8', '2001:db8::/32']],
            'expires_at' => ['old' => null, 'new' => '2027-02-01T23:59:59Z'],
        ]);
});

it('audits an update that changes nothing as an entry without changes', function () {
    $token = AccessToken::factory()->create(['name' => 'same']);

    app(UpdateAccessToken::class)->handle($token, 'same', [], null, null);

    expect(AuditEntry::query()->sole()->properties->toArray())->toEqual([]);
});

it('treats an empty allowlist as no restriction', function () {
    $token = AccessToken::factory()->withAllowedIps(['203.0.113.7'])->create();

    app(UpdateAccessToken::class)->handle($token, $token->name, [], null);

    expect($token->fresh()->allowed_ips)->toBeNull();
});

it('rejects an invalid allowlist entry and changes nothing', function () {
    $token = AccessToken::factory()->create(['name' => 'keep']);

    expect(fn () => app(UpdateAccessToken::class)->handle($token, 'changed', ['10.0.0.0/33'], null))
        ->toThrow(InvalidArgumentException::class);

    expect($token->fresh()->name)->toBe('keep')
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('never touches the token value or the revocation when updating', function () {
    $token = AccessToken::factory()->revoked()->create();
    $before = $token->fresh()->only(['token_prefix', 'token_hash', 'revoked_at']);

    app(UpdateAccessToken::class)->handle($token, 'renamed', null, null);

    expect($token->fresh()->only(['token_prefix', 'token_hash', 'revoked_at']))->toEqual($before);
});

it('deletes a token, keeps its project and audits it with a label', function () {
    $project = Project::factory()->create();
    $token = AccessToken::factory()->for($project)->create(['name' => 'prod-web-01', 'token_prefix' => 'AbCdEfGh']);

    app(DeleteAccessToken::class)->handle($token);

    expect(AccessToken::query()->count())->toBe(0)
        ->and(Project::query()->count())->toBe(1);

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('token_deleted')
        ->and($entry->subject_id)->toBe($token->id)
        ->and($entry->properties->toArray())->toEqual(['name' => 'prod-web-01', 'token_prefix' => 'AbCdEfGh', 'subject_label' => 'prod-web-01'])
        ->and(json_encode($entry))->not->toContain($token->token_hash);
});

it('keeps the access log rows of a deleted token without a reference', function () {
    $token = AccessToken::factory()->create();
    $log = AccessLog::factory()->create(['access_token_id' => $token->id]);

    app(DeleteAccessToken::class)->handle($token);

    expect($log->fresh()->access_token_id)->toBeNull();
});
