<?php

use App\Actions\Vault\ChangeAdminRole;
use App\Actions\Vault\CreateCredential;
use App\Actions\Vault\CreateProject;
use App\Actions\Vault\DeactivateAdmin;
use App\Actions\Vault\DeleteAccessToken;
use App\Actions\Vault\DeleteCredential;
use App\Actions\Vault\DeleteProject;
use App\Actions\Vault\InviteAdmin;
use App\Actions\Vault\IssueAccessToken;
use App\Actions\Vault\LinkCredentialToProjects;
use App\Actions\Vault\LinkProjectToCredentials;
use App\Actions\Vault\RegenerateAccessToken;
use App\Actions\Vault\RevokeAccessToken;
use App\Actions\Vault\RevokeInvitation;
use App\Actions\Vault\UpdateAccessToken;
use App\Actions\Vault\UpdateCredential;
use App\Actions\Vault\UpdateProject;
use App\Enums\AdminRole;
use App\Enums\CredentialType;
use App\Models\AccessToken;
use App\Models\Credential;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Vault\Settings\VaultSettings;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The matrix of the plan, written out by hand so a change to the gates cannot change the expectation with it.
 * Every action that changes data, run as every role.
 */
function roleMatrix(): array
{
    $manage = ['owner' => true, 'admin' => true, 'auditor' => false];
    $ownerOnly = ['owner' => true, 'admin' => false, 'auditor' => false];

    return [
        'create project' => [fn () => app(CreateProject::class)->handle(['name' => 'Shop', 'slug' => 'shop-'.fake()->unique()->word()]), $manage],
        'update project' => [fn () => app(UpdateProject::class)->handle(Project::factory()->create(), ['name' => 'Renamed']), $manage],
        'delete project' => [fn () => app(DeleteProject::class)->handle(Project::factory()->create()), $manage],
        'create credential' => [fn () => app(CreateCredential::class)->handle(['name' => 'Key', 'type' => CredentialType::Bearer, 'host' => 'example.com'], 'secret-value', []), $manage],
        'update credential' => [fn () => app(UpdateCredential::class)->handle(Credential::factory()->create(), ['name' => 'Renamed']), $manage],
        'delete credential' => [fn () => app(DeleteCredential::class)->handle(Credential::factory()->create()), $manage],
        'link credential to projects' => [fn () => app(LinkCredentialToProjects::class)->handle(Credential::factory()->create(), []), $manage],
        'link project to credentials' => [fn () => app(LinkProjectToCredentials::class)->handle(Project::factory()->create(), []), $manage],
        'issue token' => [fn () => app(IssueAccessToken::class)->handle('ci', [], null, null, auth()->user()), $manage],
        'update token' => [fn () => app(UpdateAccessToken::class)->handle(AccessToken::factory()->create(), 'renamed', [], null, null), $manage],
        'regenerate token' => [fn () => app(RegenerateAccessToken::class)->handle(AccessToken::factory()->create()), $manage],
        'revoke token' => [fn () => app(RevokeAccessToken::class)->handle(AccessToken::factory()->create()), $manage],
        'delete token' => [fn () => app(DeleteAccessToken::class)->handle(AccessToken::factory()->create()), $manage],
        'invite admin' => [fn () => app(InviteAdmin::class)->handle('new@example.test', auth()->user()), $ownerOnly],
        'revoke invitation' => [fn () => app(RevokeInvitation::class)->handle(Invitation::factory()->create()), $ownerOnly],
        'deactivate admin' => [fn () => app(DeactivateAdmin::class)->handle(User::factory()->admin()->create(), auth()->user()), $ownerOnly],
        'change role' => [fn () => app(ChangeAdminRole::class)->handle(User::factory()->admin()->create(), AdminRole::Auditor, auth()->user()), $ownerOnly],
        'change settings' => [fn () => app(VaultSettings::class)->update(['retention.audit_log_days' => 200], auth()->user()), $ownerOnly],
    ];
}

it('allows or refuses every action per role', function (string $role) {
    foreach (roleMatrix() as $name => [$run, $allowed]) {
        $user = User::factory()->withTwoFactor()->create(['role' => AdminRole::from($role)]);
        $this->actingAs($user);

        if ($allowed[$role]) {
            expect(fn () => $run())->not->toThrow(AuthorizationException::class, "{$role} should be allowed to {$name}");
        } else {
            expect(fn () => $run())->toThrow(AuthorizationException::class, '', "{$role} should not be allowed to {$name}");
        }
    }
})->with(['owner', 'admin', 'auditor']);

it('refuses every action for nobody logged in', function () {
    foreach (roleMatrix() as [$run]) {
        $refused = false;

        try {
            $run();
        } catch (Throwable) {
            $refused = true;
        }

        expect($refused)->toBeTrue();
    }
});

it('refuses every action for a deactivated admin', function () {
    $this->actingAs(User::factory()->create(['deactivated_at' => now()]));

    foreach (roleMatrix() as $name => [$run]) {
        expect(fn () => $run())->toThrow(AuthorizationException::class);
    }
});
