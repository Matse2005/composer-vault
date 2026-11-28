<?php

use App\Actions\Vault\CreateCredential;
use App\Actions\Vault\CreateProject;
use App\Actions\Vault\DeleteCredential;
use App\Actions\Vault\DeleteProject;
use App\Actions\Vault\IssueAccessToken;
use App\Actions\Vault\MoveCredential;
use App\Actions\Vault\RegenerateAccessToken;
use App\Actions\Vault\RevokeAccessToken;
use App\Actions\Vault\UpdateCredential;
use App\Actions\Vault\UpdateProject;
use App\Enums\CredentialType;
use App\Exceptions\Vault\HostConflictException;
use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditChainVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

/**
 * @return Collection<int, AuditEntry>
 */
function auditEntries(): Collection
{
    return AuditEntry::query()->orderBy('sequence')->get();
}

it('audits a project being created, updated and deleted', function () {
    $project = app(CreateProject::class)->handle(['name' => 'Shop', 'slug' => 'shop', 'notes' => 'Contact Jane Doe']);
    app(UpdateProject::class)->handle($project, ['name' => 'Webshop', 'notes' => 'Contact John Smith']);
    app(DeleteProject::class)->handle($project);

    $entries = auditEntries();

    expect($entries->pluck('event')->all())->toBe(['project_created', 'project_updated', 'project_deleted'])
        ->and($entries->pluck('subject_id')->unique()->all())->toBe([$project->id])
        ->and($entries[0]->properties->toArray())->toEqual(['name' => 'Shop', 'slug' => 'shop', 'notes' => 'changed'])
        ->and($entries[1]->properties->toArray())->toEqual(['name' => ['old' => 'Shop', 'new' => 'Webshop'], 'notes' => 'changed'])
        ->and($entries[2]->properties->toArray())->toEqual(['name' => 'Webshop', 'slug' => 'shop', 'subject_label' => 'Webshop'])
        ->and((string) $entries[0]->causer_id)->toBe((string) $this->admin->id)
        ->and(json_encode($entries))->not->toContain('Jane Doe')->not->toContain('John Smith');
});

it('audits a credential being created with exactly one entry', function () {
    $project = Project::factory()->create();

    $credential = app(CreateCredential::class)->handle(
        $project,
        ['name' => 'Magento', 'type' => CredentialType::HttpBasic, 'host' => 'repo.magento.com', 'username' => 'public-key', 'notes' => 'Ask Jane'],
        'plaintext-secret-marker',
    );

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('credential_created')
        ->and($entry->subject_id)->toBe($credential->id)
        ->and($entry->properties->toArray())->toEqual([
            'name' => 'Magento',
            'type' => 'http-basic',
            'host' => 'repo.magento.com',
            'username' => 'public-key',
            'project_id' => $project->id,
            'secret' => 'changed',
        ]);
});

it('audits one entry for an update and two when the secret changes too', function () {
    $credential = Credential::factory()->forHost('repo.magento.com')->withSecret('old-secret')->create(['name' => 'Old name']);

    app(UpdateCredential::class)->handle($credential, ['name' => 'New name']);
    $afterRename = auditEntries();

    app(UpdateCredential::class)->handle($credential, ['name' => 'Third name'], 'brand-new-secret');
    $afterBoth = auditEntries();

    expect($afterRename->pluck('event')->all())->toBe(['credential_updated'])
        ->and($afterRename[0]->properties->toArray())->toEqual(['name' => ['old' => 'Old name', 'new' => 'New name']])
        ->and($afterBoth->pluck('event')->all())->toBe(['credential_updated', 'credential_updated', 'credential_secret_changed'])
        ->and($afterBoth[2]->properties->toArray())->toEqual(['secret' => 'changed']);
});

it('audits an update that changes only the secret as an update without changes plus the secret entry', function () {
    $credential = Credential::factory()->withSecret('old-secret')->create();

    app(UpdateCredential::class)->handle($credential, [], 'brand-new-secret');

    $entries = auditEntries();

    expect($entries->pluck('event')->all())->toBe(['credential_updated', 'credential_secret_changed'])
        ->and($entries[0]->properties->toArray())->toEqual([]);
});

it('audits a credential being deleted with a label', function () {
    $credential = Credential::factory()->forHost('repo.magento.com')->create(['name' => 'Magento']);

    app(DeleteCredential::class)->handle($credential);

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('credential_deleted')
        ->and($entry->subject_id)->toBe($credential->id)
        ->and($entry->properties->toArray())->toEqual(['name' => 'Magento', 'host' => 'repo.magento.com', 'subject_label' => 'Magento']);
});

it('audits moving a credential with both project ids and nothing when the project stays', function () {
    [$from, $to] = Project::factory()->count(2)->create();
    $credential = Credential::factory()->for($from)->create();

    app(MoveCredential::class)->handle($credential, $to);
    app(MoveCredential::class)->handle($credential, $to);

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('credential_moved')
        ->and($entry->subject_id)->toBe($credential->id)
        ->and($entry->properties->toArray())->toEqual(['from_project_id' => $from->id, 'to_project_id' => $to->id]);
});

it('audits issuing, regenerating and revoking a token', function () {
    $project = Project::factory()->create();
    $expiresAt = CarbonImmutable::parse('2027-01-01 12:00:00', 'UTC');

    $issued = app(IssueAccessToken::class)->handle($project, 'prod-web-01', ['203.0.113.7'], $expiresAt, $this->admin);
    $oldPrefix = $issued->token->token_prefix;
    $regenerated = app(RegenerateAccessToken::class)->handle($issued->token);
    app(RevokeAccessToken::class)->handle($issued->token);
    app(RevokeAccessToken::class)->handle($issued->token);

    $entries = auditEntries();

    expect($entries->pluck('event')->all())->toBe(['token_issued', 'token_regenerated', 'token_revoked'])
        ->and($entries->pluck('subject_id')->unique()->all())->toBe([$issued->token->id])
        ->and($entries[0]->properties->toArray())->toEqual([
            'name' => 'prod-web-01',
            'token_prefix' => $oldPrefix,
            'project_id' => $project->id,
            'allowed_ips' => ['203.0.113.7'],
            'expires_at' => '2027-01-01T12:00:00Z',
        ])
        ->and($entries[1]->properties->toArray())->toEqual(['old_prefix' => $oldPrefix, 'new_prefix' => $regenerated->token->token_prefix])
        ->and($entries[2]->properties->toArray())->toEqual(['token_prefix' => $regenerated->token->token_prefix]);
});

it('refuses to issue a token when nobody is logged in and audits nothing', function () {
    $this->app['auth']->forgetGuards();

    expect(fn () => app(IssueAccessToken::class)->handle(Project::factory()->create(), 'installer', null, null, null))
        ->toThrow(AuthorizationException::class);

    expect(AuditEntry::query()->count())->toBe(0);
});

it('writes no entry when an action fails', function () {
    $project = Project::factory()->create();
    Credential::factory()->for($project)->forHost('repo.magento.com')->create();
    $other = Credential::factory()->forHost('repo.magento.com')->create();

    expect(fn () => app(MoveCredential::class)->handle($other, $project))->toThrow(HostConflictException::class)
        ->and(fn () => app(CreateCredential::class)->handle(
            $project,
            ['name' => 'Duplicate', 'type' => CredentialType::Bearer, 'host' => 'repo.magento.com'],
            'secret',
        ))->toThrow(HostConflictException::class);

    expect(AuditEntry::query()->count())->toBe(0)
        ->and(DB::table('audit_log_head')->value('sequence'))->toBe(0);
});

it('audits a key rotation without any key material', function () {
    Credential::factory()->count(2)->create();

    $this->artisan('vault:rotate-key', ['--key' => vaultTestKey(2)])->assertSuccessful();

    $entry = AuditEntry::query()->sole();

    expect($entry->event)->toBe('key_rotated')
        ->and($entry->subject_id)->toBeNull()
        ->and($entry->properties->toArray())->toEqual(['old_version' => 1, 'new_version' => 2, 'credential_count' => 2])
        ->and(json_encode($entry))->not->toContain(substr(vaultTestKey(2), 7));
});

it('does not write an audit entry for a rotation that fails', function () {
    $credential = Credential::factory()->create();
    DB::table('credentials')->where('id', $credential->id)->update(['data_key_ciphertext' => base64_encode('corrupted')]);

    $this->artisan('vault:rotate-key', ['--key' => vaultTestKey(2)])->assertFailed();

    expect(AuditEntry::query()->count())->toBe(0);
});

it('never stores a secret, ciphertext, token hash or plain token in the audit log', function () {
    $project = app(CreateProject::class)->handle(['name' => 'Shop', 'slug' => 'shop']);
    $credential = app(CreateCredential::class)->handle(
        $project,
        ['name' => 'Magento', 'type' => CredentialType::HttpBasic, 'host' => 'repo.magento.com', 'username' => 'public-key'],
        'plaintext-secret-marker',
    );
    app(UpdateCredential::class)->handle($credential, ['name' => 'Renamed'], 'second-plaintext-secret-marker');
    $issued = app(IssueAccessToken::class)->handle($project, 'prod-web-01', null, null, $this->admin);
    $regenerated = app(RegenerateAccessToken::class)->handle($issued->token);
    app(RevokeAccessToken::class)->handle($issued->token);
    $this->artisan('vault:rotate-key', ['--key' => vaultTestKey(2)])->assertSuccessful();

    $dump = json_encode([DB::table('activity_log')->get(), DB::table('audit_log_head')->get()]);
    $stored = $credential->fresh();
    $token = AccessToken::query()->find($issued->token->id);

    foreach ([
        'plaintext-secret-marker',
        'second-plaintext-secret-marker',
        $stored->secret_ciphertext,
        $stored->data_key_ciphertext,
        $token->token_hash,
        hash('sha256', $issued->plainToken),
        $issued->plainToken,
        $regenerated->plainToken,
        substr($regenerated->plainToken, 3),
    ] as $forbidden) {
        expect($dump)->not->toContain($forbidden);
    }

    expect(auditEntries()->count())->toBe(8)
        ->and(app(AuditChainVerifier::class)->verify())->toBeNull();
});
