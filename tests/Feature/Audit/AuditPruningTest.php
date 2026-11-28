<?php

use App\Enums\AuditAction;
use App\Exceptions\Vault\AuditPruneRefusedException;
use App\Models\AccessLog;
use App\Models\AuditEntry;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditChainVerifier;
use App\Vault\Audit\AuditLogger;
use App\Vault\Audit\AuditPruner;
use App\Vault\Settings\VaultSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Write one audit entry per day for the given number of days, the oldest first, ending today.
 */
function auditDays(int $days): void
{
    $project = Project::factory()->create();

    for ($daysAgo = $days - 1; $daysAgo >= 0; $daysAgo--) {
        test()->travelTo(now()->subDays($daysAgo)->subHour());
        app(AuditLogger::class)->record(AuditAction::ProjectUpdated, $project, ['day' => $daysAgo]);
        test()->travelBack();
    }
}

it('removes the entries past retention, keeps the rest and keeps the chain verifiable', function () {
    auditDays(10);

    $removed = app(AuditPruner::class)->prune(5);

    expect($removed)->toBe(5)
        ->and(AuditEntry::query()->where('event', 'project_updated')->count())->toBe(5)
        ->and(AuditEntry::query()->orderBy('sequence')->first()->sequence)->toBe(6)
        ->and(app(AuditChainVerifier::class)->verify())->toBeNull();

    $checkpoint = DB::table('audit_log_checkpoints')->sole();

    expect($checkpoint->sequence)->toBe(5)->and($checkpoint->pruned_count)->toBe(5);
});

it('audits the prune itself with the count and checkpoint', function () {
    auditDays(10);

    app(AuditPruner::class)->prune(5);

    $entry = AuditEntry::query()->where('event', 'audit_log_pruned')->sole();

    expect($entry->properties->toArray())->toBe(['pruned_count' => 5, 'checkpoint_sequence' => 5])
        ->and($entry->causer_id)->toBeNull()
        ->and(app(AuditChainVerifier::class)->verify())->toBeNull();
});

it('prunes again from the latest checkpoint and the chain still verifies', function () {
    auditDays(10);
    app(AuditPruner::class)->prune(7);
    $this->travel(3)->days();
    app(AuditPruner::class)->prune(2);

    expect(DB::table('audit_log_checkpoints')->count())->toBe(2)
        ->and(app(AuditChainVerifier::class)->verify())->toBeNull();
});

it('never removes the latest entry, even when every entry is old', function () {
    auditDays(3);
    $this->travel(400)->days();

    app(AuditPruner::class)->prune(90);

    expect(AuditEntry::query()->count())->toBe(2)
        ->and(AuditEntry::query()->orderByDesc('sequence')->first()->event)->toBe('audit_log_pruned')
        ->and(app(AuditChainVerifier::class)->verify())->toBeNull();
});

it('does nothing when no entry is past retention', function () {
    auditDays(3);

    expect(app(AuditPruner::class)->prune(30))->toBe(0)
        ->and(DB::table('audit_log_checkpoints')->count())->toBe(0)
        ->and(AuditEntry::query()->where('event', 'audit_log_pruned')->count())->toBe(0);
});

it('deletes nothing when the chain inside the pruned range was tampered with', function () {
    auditDays(10);
    DB::table('activity_log')->where('sequence', 3)->update(['event' => 'tampered']);

    expect(fn () => app(AuditPruner::class)->prune(5))->toThrow(AuditPruneRefusedException::class, 'broken at entry 3');

    expect(AuditEntry::query()->count())->toBe(10)
        ->and(DB::table('audit_log_checkpoints')->count())->toBe(0);
});

it('detects entries deleted without a checkpoint', function () {
    auditDays(6);
    DB::table('activity_log')->where('sequence', '<=', 2)->delete();

    expect(app(AuditChainVerifier::class)->verify())->toBe(3);
});

it('detects a forged checkpoint', function () {
    auditDays(10);
    app(AuditPruner::class)->prune(5);
    DB::table('audit_log_checkpoints')->update(['hash' => str_repeat('a', 64)]);

    expect(app(AuditChainVerifier::class)->verify())->toBe(6);
});

it('detects removed entries behind a checkpoint that is too low', function () {
    auditDays(10);
    app(AuditPruner::class)->prune(5);
    DB::table('activity_log')->where('sequence', 6)->delete();

    expect(app(AuditChainVerifier::class)->verify())->toBe(7);
});

it('keeps the model refusing deletes', function () {
    auditDays(2);

    expect(fn () => AuditEntry::query()->first()->delete())->toThrow(LogicException::class);
});

it('is the only code that deletes from the audit tables', function () {
    $offenders = collect(glob(base_path('app/**/*.php')) + glob(base_path('app/*/*/*.php')) + glob(base_path('app/*/*/*/*.php')))
        ->filter(fn (string $file): bool => ! str_ends_with($file, 'AuditPruner.php') && preg_match('/audit_log_checkpoints|activity_log[\'"].*delete|AuditEntry::.*delete\(|table\(\(new AuditEntry\)/s', (string) file_get_contents($file)) === 1)
        ->map(fn (string $file): string => basename($file))
        ->unique()
        ->sort()
        ->values()
        ->all();

    expect($offenders)->toBe(['AuditChainVerifier.php']);
});

it('prunes every kind of data past its retention with one command', function () {
    config(['session.driver' => 'database']);
    $settings = app(VaultSettings::class);
    $settings->update(['retention.access_log_days' => 30, 'retention.invitation_days' => 7, 'retention.audit_log_days' => 90], User::factory()->create());

    AccessLog::factory()->create(['created_at' => now()->subDays(31)]);
    $recentLog = AccessLog::factory()->create(['created_at' => now()->subDays(29)]);
    $oldInvitation = Invitation::factory()->create(['expires_at' => now()->subDays(8)]);
    $acceptedOld = Invitation::factory()->create(['accepted_at' => now()->subDays(9), 'expires_at' => now()->addDay()]);
    $openInvitation = Invitation::factory()->create(['expires_at' => now()->addDay()]);
    $recentlyExpired = Invitation::factory()->create(['expires_at' => now()->subDays(2)]);
    DB::table('sessions')->insert([
        ['id' => 'old', 'user_id' => null, 'ip_address' => '1.1.1.1', 'user_agent' => 't', 'payload' => '', 'last_activity' => now()->subDays(3)->getTimestamp()],
        ['id' => 'live', 'user_id' => null, 'ip_address' => '1.1.1.1', 'user_agent' => 't', 'payload' => '', 'last_activity' => time()],
    ]);

    $this->artisan('vault:prune')->assertSuccessful();

    expect(AccessLog::query()->pluck('id')->all())->toBe([$recentLog->id])
        ->and(Invitation::query()->pluck('id')->sort()->values()->all())->toBe(collect([$openInvitation->id, $recentlyExpired->id])->sort()->values()->all())
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['live']);
});

it('fails loudly and deletes no audit entry when the chain is broken', function () {
    auditDays(10);
    DB::table('activity_log')->where('sequence', 2)->update(['event' => 'tampered']);
    app(VaultSettings::class)->update(['retention.audit_log_days' => 90], User::factory()->create());

    $this->travel(100)->days();

    $this->artisan('vault:prune')->assertFailed();

    expect(AuditEntry::query()->where('sequence', '<=', 10)->count())->toBe(10);
});

it('is scheduled every day, next to the pruning of health results', function () {
    $commands = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');

    expect($commands)->toContain('vault:prune')->toContain('model:prune');
});

it('edits the retention on the page and shows what the next prune deletes', function () {
    auditDays(4);
    $this->actingAs(User::factory()->withTwoFactor()->create());
    $this->travel(200)->days();

    Livewire::test('pages::settings.retention')
        ->assertSee('Audit entries older than 365 days')
        ->set('auditLogDays', '90')
        ->assertSee('deletes 4 entries')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(VaultSettings::class)->auditLogRetentionDays())->toBe(90);

    Livewire::test('pages::settings.retention')->set('auditLogDays', '10')->set('accessLogDays', '5')->call('save')
        ->assertHasErrors(['auditLogDays', 'accessLogDays']);
});

it('keeps the retention page for owners', function () {
    $this->actingAs(User::factory()->withTwoFactor()->admin()->create());

    $this->get(route('settings.retention'))->assertForbidden();
});
