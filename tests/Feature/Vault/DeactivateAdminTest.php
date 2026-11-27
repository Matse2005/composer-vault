<?php

use App\Actions\Vault\DeactivateAdmin;
use App\Exceptions\Vault\CannotDeactivateAdminException;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actor = User::factory()->withTwoFactor()->create(['name' => 'Alice Actor']);
    $this->actingAs($this->actor);
});

it('deactivates another admin and audits it', function () {
    $other = User::factory()->withTwoFactor()->create();

    app(DeactivateAdmin::class)->handle($other, $this->actor);

    expect($other->fresh()->deactivated_at)->not->toBeNull()
        ->and($other->fresh()->isDeactivated())->toBeTrue()
        ->and(User::query()->count())->toBe(2);

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('admin_deactivated')
        ->and($entry->subject_id)->toBe((string) $other->id)
        ->and((string) $entry->causer_id)->toBe((string) $this->actor->id)
        ->and(json_encode($entry))->not->toContain($other->email)->not->toContain($other->name);
});

it('refuses to deactivate yourself', function () {
    User::factory()->create();

    expect(fn () => app(DeactivateAdmin::class)->handle($this->actor, $this->actor))
        ->toThrow(CannotDeactivateAdminException::class, 'yourself');

    expect($this->actor->fresh()->deactivated_at)->toBeNull()
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('refuses to deactivate the last active admin', function () {
    $other = User::factory()->create();
    $other->forceFill(['deactivated_at' => now()])->save();
    $onlyActive = User::factory()->create();
    $this->actor->forceFill(['deactivated_at' => now()])->save();

    expect(fn () => app(DeactivateAdmin::class)->handle($onlyActive, $this->actor))
        ->toThrow(CannotDeactivateAdminException::class, 'last active admin');

    expect($onlyActive->fresh()->deactivated_at)->toBeNull();
});

it('counts a deactivated admin as no admin when looking for the last one', function () {
    $second = User::factory()->create();
    $third = User::factory()->create();
    app(DeactivateAdmin::class)->handle($third, $this->actor);

    app(DeactivateAdmin::class)->handle($second, $this->actor);

    expect(fn () => app(DeactivateAdmin::class)->handle($this->actor, $second))
        ->toThrow(CannotDeactivateAdminException::class);
});

it('does nothing for an admin who is already deactivated', function () {
    $other = User::factory()->create();
    app(DeactivateAdmin::class)->handle($other, $this->actor);
    $deactivatedAt = $other->fresh()->deactivated_at;
    $this->travel(1)->hours();

    app(DeactivateAdmin::class)->handle($other->fresh(), $this->actor);

    expect($other->fresh()->deactivated_at->toDateTimeString())->toBe($deactivatedAt->toDateTimeString())
        ->and(AuditEntry::query()->count())->toBe(1);
});

it('ends the sessions of a deactivated admin when sessions are stored in the database', function () {
    config(['session.driver' => 'database']);
    $other = User::factory()->create();
    $mine = $this->actor;
    foreach ([['one', $other->id], ['two', $other->id], ['three', $mine->id]] as [$id, $userId]) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
    }

    app(DeactivateAdmin::class)->handle($other, $this->actor);

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['three']);
});

it('leaves sessions alone when they are not stored in the database', function () {
    config(['session.driver' => 'array']);
    $other = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'one', 'user_id' => $other->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);

    app(DeactivateAdmin::class)->handle($other, $this->actor);

    expect(DB::table('sessions')->count())->toBe(1);
});

it('invalidates the remember me token of a deactivated admin', function () {
    $other = User::factory()->create(['remember_token' => 'remember-token-value']);

    app(DeactivateAdmin::class)->handle($other, $this->actor);

    expect($other->fresh()->getRememberToken())->not->toBe('remember-token-value');
});
