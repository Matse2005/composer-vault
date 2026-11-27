<?php

use App\Enums\AuditAction;
use App\Models\Project;
use App\Vault\Audit\AuditChainVerifier;
use App\Vault\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

function writeAuditEntries(int $count): void
{
    $project = Project::factory()->create();

    foreach (range(1, $count) as $number) {
        app(AuditLogger::class)->record(AuditAction::ProjectUpdated, $project, ['name' => "Name {$number}", 'rank' => $number]);
    }
}

function verifyAuditChain(): ?int
{
    return app(AuditChainVerifier::class)->verify();
}

it('accepts an empty chain', function () {
    expect(verifyAuditChain())->toBeNull();
});

it('accepts an intact chain', function () {
    writeAuditEntries(12);

    expect(verifyAuditChain())->toBeNull();
});

it('accepts an intact chain that spans several chunks', function () {
    writeAuditEntries(1200);

    expect(DB::table('activity_log')->count())->toBe(1200)
        ->and(verifyAuditChain())->toBeNull();
});

it('detects an edited property', function () {
    writeAuditEntries(10);

    DB::table('activity_log')->where('sequence', 5)->update(['properties' => json_encode(['name' => 'Tampered'])]);

    expect(verifyAuditChain())->toBe(5);
});

it('detects an edited event', function () {
    writeAuditEntries(10);

    DB::table('activity_log')->where('sequence', 4)->update(['event' => 'project_deleted']);

    expect(verifyAuditChain())->toBe(4);
});

it('detects an edited description, address, causer or time', function (string $column, mixed $value) {
    writeAuditEntries(10);

    DB::table('activity_log')->where('sequence', 7)->update([$column => $value]);

    expect(verifyAuditChain())->toBe(7);
})->with([
    'description' => ['description', 'Something else'],
    'ip' => ['ip', '198.51.100.1'],
    'causer' => ['causer_id', '99'],
    'subject' => ['subject_id', 'another-subject'],
    'time' => ['created_at', '2020-01-01 00:00:00'],
    'log name' => ['log_name', 'default'],
]);

it('detects an edited hash and an edited previous hash', function (string $column) {
    writeAuditEntries(10);

    DB::table('activity_log')->where('sequence', 6)->update([$column => str_repeat('a', 64)]);

    expect(verifyAuditChain())->toBe(6);
})->with(['hash', 'previous_hash']);

it('detects a deleted middle entry at the first entry that no longer links', function () {
    writeAuditEntries(10);

    DB::table('activity_log')->where('sequence', 5)->delete();

    expect(verifyAuditChain())->toBe(6);
});

it('detects a deleted first entry', function () {
    writeAuditEntries(10);

    DB::table('activity_log')->where('sequence', 1)->delete();

    expect(verifyAuditChain())->toBe(2);
});

it('detects a deleted last entry through the head row', function () {
    writeAuditEntries(10);

    DB::table('activity_log')->where('sequence', 10)->delete();

    expect(verifyAuditChain())->toBe(10);
});

it('detects entries that were added without moving the head', function () {
    writeAuditEntries(10);
    DB::table('audit_log_head')->where('id', 1)->update(['sequence' => 9]);

    expect(verifyAuditChain())->toBe(10);
});

it('detects a changed head hash', function () {
    writeAuditEntries(10);

    DB::table('audit_log_head')->where('id', 1)->update(['last_hash' => str_repeat('b', 64)]);

    expect(verifyAuditChain())->toBe(10);
});

it('detects two swapped sequences', function () {
    writeAuditEntries(10);

    DB::table('activity_log')->where('sequence', 3)->update(['sequence' => 1003]);
    DB::table('activity_log')->where('sequence', 4)->update(['sequence' => 3]);
    DB::table('activity_log')->where('sequence', 1003)->update(['sequence' => 4]);

    expect(verifyAuditChain())->toBe(3);
});

it('detects a removed head row', function () {
    writeAuditEntries(3);

    DB::table('audit_log_head')->delete();

    expect(verifyAuditChain())->not->toBeNull();
});

it('detects entries on an empty head', function () {
    writeAuditEntries(3);

    DB::table('audit_log_head')->where('id', 1)->update(['sequence' => 0, 'last_hash' => str_repeat('0', 64)]);

    expect(verifyAuditChain())->toBe(1);
});

it('detects an entry written without a sequence', function () {
    writeAuditEntries(3);

    DB::table('activity_log')->insert([
        'log_name' => 'audit', 'description' => 'Injected', 'event' => 'project_created',
        'properties' => '[]', 'created_at' => now(),
    ]);

    expect(verifyAuditChain())->not->toBeNull();
});
