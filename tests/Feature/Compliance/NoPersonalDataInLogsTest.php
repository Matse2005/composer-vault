<?php

use App\Actions\Vault\AcceptInvitation;
use App\Actions\Vault\ChangeAdminRole;
use App\Actions\Vault\CompleteAccessReview;
use App\Actions\Vault\DeactivateAdmin;
use App\Actions\Vault\ExportAdminPersonalData;
use App\Actions\Vault\InviteAdmin;
use App\Actions\Vault\PseudonymiseAdmin;
use App\Actions\Vault\ReactivateAdmin;
use App\Actions\Vault\SaveIncident;
use App\Actions\Vault\SetBreakGlass;
use App\Enums\AdminRole;
use App\Models\AccessToken;
use App\Models\Project;
use App\Models\User;
use App\Vault\Settings\VaultSettings;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeOidcProvider;

it('keeps every name and email address out of the audit log and the access log', function () {
    $names = ['Olivia Owner', 'Adam Admin', 'Invited Ingrid', 'Sso Person Example', 'Leaver Lars'];
    $emails = ['olivia.owner@example.test', 'adam.admin@example.test', 'ingrid.invited@example.test', 'sso.person@example.test', 'lars.leaver@example.test', 'security.contact@acme.test', 'alerts@acme.test'];

    $owner = User::factory()->withTwoFactor()->create(['name' => $names[0], 'email' => $emails[0]]);
    $admin = User::factory()->withTwoFactor()->admin()->create(['name' => $names[1], 'email' => $emails[1]]);
    $leaver = User::factory()->admin()->create(['name' => $names[4], 'email' => $emails[4]]);
    $this->actingAs($owner);

    // Admin lifecycle: invite, accept, role change, break-glass, deactivate, export, reactivate, pseudonymise.
    $issued = app(InviteAdmin::class)->handle($emails[2], $owner, AdminRole::Auditor);
    app(AcceptInvitation::class)->handle(basename(parse_url($issued->url, PHP_URL_PATH)), $names[2], 'a-long-password-123');
    app(ChangeAdminRole::class)->handle($admin, AdminRole::Auditor, $owner);
    app(SetBreakGlass::class)->handle($owner, true, User::factory()->create());
    app(DeactivateAdmin::class)->handle($leaver, $owner);
    app(ExportAdminPersonalData::class)->handle($leaver, $owner);
    app(ReactivateAdmin::class)->handle($leaver, $owner);
    app(DeactivateAdmin::class)->handle($leaver, $owner);
    app(PseudonymiseAdmin::class)->handle($leaver, $owner);

    // Settings, incidents and reviews with free text and addresses.
    $settings = app(VaultSettings::class);
    $settings->update(['organisation.name' => 'Acme Org', 'organisation.security_contact' => $emails[5], 'notifications.mail' => $emails[6], 'retention.audit_log_days' => 200], $owner);
    app(SaveIncident::class)->handle(null, 'Incident about Olivia Owner', now()->subHour(), null, 'Notes mention '.$names[0], $owner);
    app(CompleteAccessReview::class)->handle('Reviewed with '.$names[1], $owner);

    // Single sign-on: a rejected attempt and a provisioned person.
    $this->idp = (new FakeOidcProvider)->install(['vault.sso.auto_provision' => true, 'vault.sso.role_groups.admin' => 'admins']);
    auth()->logout();
    $this->flushSession();
    finishSso(['email' => 'unknown.person@example.test', 'name' => 'Unknown Person'])->assertForbidden();
    auth()->logout();
    finishSso(['email' => $emails[3], 'name' => $names[3], 'groups' => ['admins'], 'amr' => ['mfa']]);

    // API traffic with failures, as an access log row is written for each.
    $project = Project::factory()->create(['slug' => 'shop']);
    [$token, $plain] = tokenWithPlain(AccessToken::factory()->for($project));
    $this->getJson('/api/v1/projects/shop/composer-auth', ['Authorization' => 'Bearer '.$plain])->assertOk();
    $this->getJson('/api/v1/projects/shop/composer-auth', ['Authorization' => 'Bearer cv_'.str_repeat('q', 40)]);

    $logs = json_encode([
        DB::table('activity_log')->get(),
        DB::table('access_logs')->get(),
    ]);

    expect(DB::table('activity_log')->count())->toBeGreaterThan(10)->and(DB::table('access_logs')->count())->toBe(2);

    foreach ([...$names, ...$emails, 'Unknown Person', 'unknown.person@example.test', 'Acme Org', 'Former admin'] as $personal) {
        expect($logs)->not->toContain($personal);
    }
});
