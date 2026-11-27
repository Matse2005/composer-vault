<?php

use App\Models\AccessToken;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

it('is active without an expiry date', function () {
    $token = AccessToken::factory()->create(['expires_at' => null]);

    expect($token->isActive())->toBeTrue()
        ->and($token->isRevoked())->toBeFalse()
        ->and($token->isExpired())->toBeFalse();
});

it('is active with an expiry date in the future', function () {
    $token = AccessToken::factory()->create(['expires_at' => now()->addDay()]);

    expect($token->isActive())->toBeTrue()
        ->and($token->isExpired())->toBeFalse();
});

it('is not active when revoked', function () {
    $token = AccessToken::factory()->revoked()->create();

    expect($token->isRevoked())->toBeTrue()
        ->and($token->isActive())->toBeFalse();
});

it('is not active when expired', function () {
    $token = AccessToken::factory()->expired()->create();

    expect($token->isExpired())->toBeTrue()
        ->and($token->isActive())->toBeFalse();
});

it('is expired exactly at the expiry moment', function () {
    $this->freezeTime();
    $token = AccessToken::factory()->create(['expires_at' => now()]);

    expect($token->isExpired())->toBeTrue();
});

it('is not active when both revoked and expired', function () {
    $token = AccessToken::factory()->revoked()->expired()->create();

    expect($token->isActive())->toBeFalse();
});

it('returns only active tokens from the active scope', function () {
    $active = AccessToken::factory()->create();
    $future = AccessToken::factory()->create(['expires_at' => now()->addWeek()]);
    AccessToken::factory()->revoked()->create();
    AccessToken::factory()->expired()->create();
    AccessToken::factory()->revoked()->expired()->create();

    expect(AccessToken::query()->active()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$active->id, $future->id])->sort()->values()->all());
});

it('casts allowed ips to an array', function () {
    $token = AccessToken::factory()->withAllowedIps(['203.0.113.7', '10.0.0.0/8'])->create();

    expect($token->refresh()->allowed_ips)->toBe(['203.0.113.7', '10.0.0.0/8'])
        ->and(AccessToken::factory()->create()->refresh()->allowed_ips)->toBeNull();
});

it('writes a sha-256 hash and a prefix by default', function () {
    $token = AccessToken::factory()->create();

    expect($token->token_prefix)->toHaveLength(8)
        ->and($token->token_hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('hides the token hash from arrays and json', function () {
    $token = AccessToken::factory()->create();

    expect($token->toArray())->not->toHaveKey('token_hash')
        ->and($token->toJson())->not->toContain($token->token_hash);
});

it('enforces a unique token prefix in the database', function () {
    AccessToken::factory()->create(['token_prefix' => 'cv_abcde']);

    AccessToken::factory()->create(['token_prefix' => 'cv_abcde']);
})->throws(UniqueConstraintViolationException::class);

it('links tokens to projects and removes the links with either side', function () {
    $token = AccessToken::factory()->create();
    $project = Project::factory()->create();
    $token->projects()->attach($project);

    expect($project->accessTokens()->pluck('access_tokens.id')->all())->toBe([$token->id]);

    $project->delete();

    expect($token->projects()->count())->toBe(0);
});

it('keeps the token and clears the creator when the admin is deleted', function () {
    $admin = User::factory()->create();
    $token = AccessToken::factory()->create(['created_by' => $admin->id]);

    expect($token->creator->is($admin))->toBeTrue();

    $admin->delete();

    expect($token->refresh()->created_by)->toBeNull();
});
