<?php

use App\Actions\Vault\ExportAdminPersonalData;
use App\Actions\Vault\PseudonymiseAdmin;
use App\Actions\Vault\ReactivateAdmin;
use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Exceptions\Vault\PseudonymisationRefusedException;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\User;
use App\Vault\Audit\AuditChainVerifier;
use App\Vault\Audit\AuditLogger;
use App\Vault\Settings\VaultSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * An admin with a second factor, a passkey, a session and some audit history.
 */
function adminWithHistory(): User
{
    config(['session.driver' => 'database']);
    $admin = User::factory()->withTwoFactor()->admin()->create(['name' => 'Jane Leaver', 'email' => 'jane@example.test', 'last_login_at' => now()->subDay()]);
    DB::table('passkeys')->insert(['user_id' => $admin->id, 'name' => 'Laptop key', 'credential_id' => 'secret-credential-id', 'credential' => '{"private":"credential-blob"}', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $admin->id, 'ip_address' => '203.0.113.7', 'user_agent' => 'Firefox', 'payload' => 'payload-secret', 'last_activity' => time()]);
    app(AuditLogger::class)->record(AuditAction::ProjectCreated, Project::factory()->create(), [], $admin);

    return $admin;
}

it('exports the personal data of an admin without any secret', function () {
    $admin = adminWithHistory();
    $owner = User::factory()->create();

    $data = app(ExportAdminPersonalData::class)->handle($admin, $owner);

    expect($data['profile'])->toMatchArray(['name' => 'Jane Leaver', 'email' => 'jane@example.test', 'role' => 'admin'])
        ->and($data['second_factor']['authenticator_app'])->toBeTrue()
        ->and($data['second_factor']['passkeys'][0]['name'])->toBe('Laptop key')
        ->and($data['sessions'][0])->toMatchArray(['ip' => '203.0.113.7', 'user_agent' => 'Firefox'])
        ->and($data['audit_entries'])->toHaveCount(1)
        ->and(array_keys($data['audit_entries'][0]))->toBe(['sequence', 'time', 'event', 'ip']);

    $json = json_encode($data);

    foreach ([$admin->password, 'secret-credential-id', 'credential-blob', 'payload-secret', 'recovery-code-1', $admin->remember_token] as $secret) {
        expect($json)->not->toContain((string) $secret);
    }
});

it('lets an admin export their own data and nobody else but an owner', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create();

    expect(app(ExportAdminPersonalData::class)->handle($admin, $admin)['profile']['id'])->toBe($admin->id)
        ->and(fn () => app(ExportAdminPersonalData::class)->handle($other, $admin))->toThrow(AuthorizationException::class)
        ->and(app(ExportAdminPersonalData::class)->handle($other, User::factory()->create())['profile']['id'])->toBe($other->id);
});

it('audits every export', function () {
    $admin = User::factory()->admin()->create();

    app(ExportAdminPersonalData::class)->handle($admin, $admin);

    $entry = AuditEntry::query()->where('event', 'personal_data_exported')->sole();

    expect((int) $entry->subject_id)->toBe($admin->id)->and($entry->causer_id)->toBe($admin->id);
});

it('downloads the export as a json file', function () {
    $admin = User::factory()->withTwoFactor()->admin()->create();
    $this->actingAs($admin);

    $response = $this->get(route('personal-data.export', $admin))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="personal-data-'.$admin->id.'.json"')
        ->and($response->json('profile.email'))->toBe($admin->email);

    $this->get(route('personal-data.export', User::factory()->create()))->assertForbidden();
});

it('offers the download on the profile page', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->get(route('profile.edit'))->assertOk()->assertSee('Download my data');
});

it('pseudonymises a deactivated admin and clears every personal field', function () {
    $admin = adminWithHistory();
    $admin->forceFill(['deactivated_at' => now(), 'sso_issuer' => 'https://idp.example.test', 'sso_subject' => 'sub-1'])->save();
    $originalPassword = $admin->password;

    app(PseudonymiseAdmin::class)->handle($admin, User::factory()->create());

    $admin->refresh();

    expect($admin->name)->toStartWith('Former admin ')->and(strlen($admin->name))->toBe(21)
        ->and($admin->email)->toBe('deleted-'.$admin->id.'@invalid')
        ->and($admin->password)->not->toBe($originalPassword)
        ->and($admin->two_factor_secret)->toBeNull()
        ->and($admin->two_factor_recovery_codes)->toBeNull()
        ->and($admin->two_factor_confirmed_at)->toBeNull()
        ->and($admin->sso_subject)->toBeNull()
        ->and($admin->sso_issuer)->toBeNull()
        ->and($admin->pseudonymised_at)->not->toBeNull()
        ->and(DB::table('passkeys')->where('user_id', $admin->id)->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $admin->id)->count())->toBe(0)
        ->and(AuditEntry::query()->where('event', 'admin_pseudonymised')->count())->toBe(1);
});

it('keeps the audit entries and the chain, and the audit page shows the pseudonym', function () {
    $admin = adminWithHistory();
    $admin->forceFill(['deactivated_at' => now()])->save();
    $entriesBefore = AuditEntry::query()->where('causer_id', $admin->id)->count();

    app(PseudonymiseAdmin::class)->handle($admin, User::factory()->create());

    expect(AuditEntry::query()->where('causer_id', $admin->id)->count())->toBe($entriesBefore)
        ->and(app(AuditChainVerifier::class)->verify())->toBeNull();

    $this->actingAs(User::factory()->withTwoFactor()->create());
    $name = $admin->fresh()->name;

    Livewire::test('pages::logs.audit')->assertSee($name)->assertDontSee('Jane Leaver');
});

it('refuses to pseudonymise an active admin and anyone who is not an owner', function () {
    $active = User::factory()->admin()->create(['name' => 'Still Here']);

    expect(fn () => app(PseudonymiseAdmin::class)->handle($active, User::factory()->create()))->toThrow(PseudonymisationRefusedException::class)
        ->and($active->fresh()->name)->toBe('Still Here');

    $gone = User::factory()->create(['deactivated_at' => now()]);

    expect(fn () => app(PseudonymiseAdmin::class)->handle($gone, User::factory()->admin()->create()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PseudonymiseAdmin::class)->handle($gone, User::factory()->auditor()->create()))->toThrow(AuthorizationException::class);
});

it('does nothing the second time', function () {
    $gone = User::factory()->create(['deactivated_at' => now()]);
    $owner = User::factory()->create();

    app(PseudonymiseAdmin::class)->handle($gone, $owner);
    $name = $gone->fresh()->name;
    app(PseudonymiseAdmin::class)->handle($gone, $owner);

    expect($gone->fresh()->name)->toBe($name)->and(AuditEntry::query()->where('event', 'admin_pseudonymised')->count())->toBe(1);
});

it('pseudonymises from the admins page and hides reactivation afterwards', function () {
    $owner = User::factory()->withTwoFactor()->create();
    $gone = User::factory()->create(['deactivated_at' => now()]);
    $this->actingAs($owner);

    Livewire::test('pages::admins.index')->assertSee('IP addresses in audit entries are not changed')->call('pseudonymise', $gone->id)
        ->assertSee('Pseudonymised');

    expect($gone->fresh()->pseudonymised_at)->not->toBeNull();
});

it('never reactivates a pseudonymised admin', function () {
    $gone = User::factory()->create(['deactivated_at' => now()]);
    app(PseudonymiseAdmin::class)->handle($gone, User::factory()->create());

    app(ReactivateAdmin::class)->handle($gone, User::factory()->create());

    expect($gone->fresh()->isDeactivated())->toBeTrue();
});

it('shows every role the categories with the configured retention', function (string $role) {
    app(VaultSettings::class)->update(['retention.audit_log_days' => 200], User::factory()->create());
    $this->actingAs(User::factory()->withTwoFactor()->create(['role' => AdminRole::from($role)]));

    $this->get(route('compliance.data-processing'))->assertOk()
        ->assertSee('Admin accounts')->assertSee('Sessions')->assertSee('Audit log')->assertSee('API access log')->assertSee('Invitations')
        ->assertSee('200 days')->assertSee('not legal advice');
})->with(['owner', 'admin', 'auditor']);
