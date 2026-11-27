<?php

use App\Enums\AuditAction;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditChainVerifier;
use App\Vault\Audit\AuditHasher;
use App\Vault\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function audit(): AuditLogger
{
    return app(AuditLogger::class);
}

it('writes an entry with the chain fields', function () {
    $project = Project::factory()->create();
    $admin = User::factory()->create();
    app()->instance('request', Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));

    $entry = audit()->record(AuditAction::ProjectCreated, $project, ['name' => 'Shop'], $admin);

    $stored = AuditEntry::query()->sole();

    expect($stored->is($entry))->toBeTrue()
        ->and($stored->sequence)->toBe(1)
        ->and($stored->previous_hash)->toBe(AuditHasher::GENESIS_HASH)
        ->and($stored->hash)->toMatch('/^[0-9a-f]{64}$/')
        ->and($stored->log_name)->toBe('audit')
        ->and($stored->event)->toBe('project_created')
        ->and($stored->description)->toBe('Project created')
        ->and($stored->subject_type)->toBe(Project::class)
        ->and($stored->subject_id)->toBe($project->id)
        ->and($stored->causer_type)->toBe(User::class)
        ->and((string) $stored->causer_id)->toBe((string) $admin->id)
        ->and($stored->ip)->toBe('203.0.113.9')
        ->and($stored->properties->toArray())->toEqual(['name' => 'Shop'])
        ->and($stored->created_at->micro)->toBe(0);
});

it('chains entries and moves the head', function () {
    $project = Project::factory()->create();

    $first = audit()->record(AuditAction::ProjectCreated, $project);
    $second = audit()->record(AuditAction::ProjectUpdated, $project);
    $head = DB::table('audit_log_head')->first();

    expect($second->sequence)->toBe(2)
        ->and($second->previous_hash)->toBe($first->hash)
        ->and($second->hash)->not->toBe($first->hash)
        ->and($head->sequence)->toBe(2)
        ->and($head->last_hash)->toBe($second->hash)
        ->and(app(AuditChainVerifier::class)->verify())->toBeNull();
});

it('uses the authenticated user as the actor by default', function () {
    $admin = User::factory()->create();
    $this->actingAs($admin);

    $entry = audit()->record(AuditAction::ProjectCreated, Project::factory()->create());

    expect((string) $entry->causer_id)->toBe((string) $admin->id);
});

it('has no causer when there is no actor, for example in the console', function () {
    $entry = audit()->record(AuditAction::KeyRotated, null, ['old_version' => 1, 'new_version' => 2]);

    expect($entry->causer_id)->toBeNull()->and($entry->subject_id)->toBeNull();
});

it('stores no ip when there is no client address', function () {
    $request = Request::create('/', 'GET');
    $request->server->remove('REMOTE_ADDR');
    app()->instance('request', $request);

    expect(audit()->record(AuditAction::KeyRotated, null)->ip)->toBeNull();
});

it('stores ulid and integer subjects as strings', function () {
    $admin = User::factory()->create();

    $forUser = audit()->record(AuditAction::AdminLoggedIn, $admin, actor: $admin);
    $forCredential = audit()->record(AuditAction::CredentialCreated, Credential::factory()->create());

    expect($forUser->fresh()->subject_id)->toBe((string) $admin->id)
        ->and($forCredential->fresh()->subject_id)->toHaveLength(26);
});

it('sanitizes the changes and adds the subject label', function () {
    $entry = audit()->record(AuditAction::CredentialDeleted, null, [
        'name' => 'Magento',
        'secret' => 'plaintext-secret-marker',
        'secret_ciphertext' => 'ciphertext-marker',
        'token_hash' => 'hash-marker',
    ], subjectLabel: 'Magento');

    expect($entry->fresh()->properties->toArray())->toEqual(['name' => 'Magento', 'secret' => 'changed', 'subject_label' => 'Magento'])
        ->and(json_encode(DB::table('activity_log')->get()))
        ->not->toContain('plaintext-secret-marker')
        ->not->toContain('ciphertext-marker')
        ->not->toContain('hash-marker');
});

it('refuses a bare activity call', function () {
    activity()->log('written without the audit logger');
})->throws(LogicException::class, 'AuditLogger');

it('refuses a bare activity call even with the audit log name', function () {
    activity('audit')->event('project_created')->log('Project created');
})->throws(LogicException::class);

it('refuses to update an audit entry', function () {
    $entry = audit()->record(AuditAction::ProjectCreated, Project::factory()->create());

    $entry->description = 'edited';
    $entry->save();
})->throws(LogicException::class);

it('refuses to delete an audit entry', function () {
    audit()->record(AuditAction::ProjectCreated, Project::factory()->create())->delete();
})->throws(LogicException::class);

it('writes entries with unique sequences in nested transactions', function () {
    $project = Project::factory()->create();

    DB::transaction(function () use ($project) {
        audit()->record(AuditAction::ProjectCreated, $project);

        DB::transaction(function () use ($project) {
            audit()->record(AuditAction::ProjectUpdated, $project);

            DB::transaction(fn () => audit()->record(AuditAction::ProjectDeleted, $project));
        });

        audit()->record(AuditAction::ProjectUpdated, $project);
    });

    expect(AuditEntry::query()->orderBy('sequence')->pluck('sequence')->all())->toBe([1, 2, 3, 4])
        ->and(app(AuditChainVerifier::class)->verify())->toBeNull();
});

it('rolls the head back together with the transaction', function () {
    $project = Project::factory()->create();

    try {
        DB::transaction(function () use ($project) {
            audit()->record(AuditAction::ProjectCreated, $project);

            throw new RuntimeException('abort');
        });
    } catch (RuntimeException) {
    }

    expect(AuditEntry::query()->count())->toBe(0)
        ->and(DB::table('audit_log_head')->value('sequence'))->toBe(0)
        ->and(audit()->record(AuditAction::ProjectCreated, $project)->sequence)->toBe(1);
});

it('names every audit action in snake case with a readable description', function () {
    foreach (AuditAction::cases() as $action) {
        expect($action->value)->toMatch('/^[a-z]+(_[a-z]+)*$/')
            ->and($action->description())->toMatch('/^[A-Z][a-z]+( [a-z]+)*$/');
    }

    expect(AuditAction::cases())->toHaveCount(24)
        ->and(AuditAction::CredentialSecretChanged->description())->toBe('Credential secret changed');
});
