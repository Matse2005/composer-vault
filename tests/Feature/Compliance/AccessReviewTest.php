<?php

use App\Actions\Vault\CompleteAccessReview;
use App\Actions\Vault\IssueAccessToken;
use App\Actions\Vault\RegenerateAccessToken;
use App\Actions\Vault\UpdateAccessToken;
use App\Enums\AdminRole;
use App\Exceptions\Vault\TokenPolicyException;
use App\Models\AccessReview;
use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use App\Vault\Compliance\AccessReviewReport;
use App\Vault\Health\AccessReviewCheck;
use App\Vault\Settings\VaultSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->owner = User::factory()->withTwoFactor()->create();
    $this->actingAs($this->owner);
});

/**
 * The reasons the report gives per token name.
 *
 * @return array<string, list<string>>
 */
function reasonsByToken(): array
{
    return collect(app(AccessReviewReport::class)->tokens())->mapWithKeys(fn ($row) => [$row['token']->name => $row['reasons']])->all();
}

it('lists the tokens that need a look with their reasons', function () {
    AccessToken::factory()->create(['name' => 'forever', 'expires_at' => null, 'allowed_ips' => ['10.0.0.1'], 'last_used_at' => now()]);
    AccessToken::factory()->create(['name' => 'soon', 'expires_at' => now()->addDays(5), 'allowed_ips' => ['10.0.0.1'], 'last_used_at' => now()]);
    AccessToken::factory()->create(['name' => 'stale', 'expires_at' => now()->addDays(200), 'allowed_ips' => ['10.0.0.1'], 'last_used_at' => now()->subDays(91)]);
    AccessToken::factory()->create(['name' => 'open', 'expires_at' => now()->addDays(200), 'allowed_ips' => null, 'last_used_at' => now()]);
    AccessToken::factory()->create(['name' => 'fine', 'expires_at' => now()->addDays(200), 'allowed_ips' => ['10.0.0.1'], 'last_used_at' => now()]);
    AccessToken::factory()->revoked()->create(['name' => 'revoked', 'expires_at' => null]);

    expect(reasonsByToken())->toBe([
        'forever' => ['No expiry'],
        'open' => ['No IP allowlist'],
        'soon' => ['Expires within 14 days'],
        'stale' => ['Not used for 90 days'],
    ]);
});

it('counts a token that was never used from its creation', function () {
    AccessToken::factory()->create(['name' => 'old unused', 'expires_at' => now()->addDays(200), 'allowed_ips' => ['10.0.0.1'], 'last_used_at' => null, 'created_at' => now()->subDays(100)]);
    AccessToken::factory()->create(['name' => 'new unused', 'expires_at' => now()->addDays(200), 'allowed_ips' => ['10.0.0.1'], 'last_used_at' => null]);

    expect(reasonsByToken())->toBe(['old unused' => ['Not used for 90 days']]);
});

it('flags tokens over the lifetime policy', function () {
    app(VaultSettings::class)->update(['policy.token_max_lifetime_days' => 30], $this->owner);
    AccessToken::factory()->create(['name' => 'long', 'expires_at' => now()->addDays(60), 'allowed_ips' => ['10.0.0.1'], 'last_used_at' => now()]);
    AccessToken::factory()->create(['name' => 'ok', 'expires_at' => now()->addDays(20), 'allowed_ips' => ['10.0.0.1'], 'last_used_at' => now()]);

    expect(reasonsByToken())->toBe(['long' => ['Expiry beyond the policy of 30 days']]);
});

it('lists credentials past the rotation policy only when one is set', function () {
    $old = Credential::factory()->create(['name' => 'old key']);
    $new = Credential::factory()->create(['name' => 'new key']);
    Credential::query()->whereKey($old->id)->update(['secret_changed_at' => now()->subDays(100)]);
    Credential::query()->whereKey($new->id)->update(['secret_changed_at' => now()->subDays(10)]);

    expect(app(AccessReviewReport::class)->credentialsDueForRotation())->toHaveCount(0);

    app(VaultSettings::class)->update(['policy.credential_rotation_days' => 60], $this->owner);

    expect(app(AccessReviewReport::class)->credentialsDueForRotation()->pluck('name')->all())->toBe(['old key']);
});

it('shows the admins with their method and second factor', function () {
    User::factory()->admin()->create(['name' => 'Sso Sam', 'sso_issuer' => 'https://idp.example.test', 'sso_subject' => 's1']);
    User::factory()->auditor()->create(['name' => 'Gone Gail', 'deactivated_at' => now()]);

    Livewire::test('pages::compliance.access-review')->assertSee('Sso Sam')->assertSee('SSO')->assertSee('Gone Gail')->assertSee('Deactivated');
});

it('completes a review with a snapshot of counts and an audit entry without the notes', function () {
    User::factory()->admin()->create();
    User::factory()->admin()->create(['deactivated_at' => now()]);
    AccessToken::factory()->create(['expires_at' => null]);

    $review = app(CompleteAccessReview::class)->handle('Checked with Jane Doe', $this->owner);

    expect($review->snapshot)->toBe(['admins_active' => 2, 'admins_deactivated' => 1, 'admins_without_second_factor' => 1, 'tokens_flagged' => 1, 'credentials_due_for_rotation' => 0])
        ->and($review->completed_by)->toBe($this->owner->id)
        ->and(AuditEntry::query()->where('event', 'access_review_completed')->count())->toBe(1)
        ->and(json_encode(AuditEntry::query()->get()->map->properties))->not->toContain('Jane Doe');
});

it('lets the owner complete from the page and shows previous reviews', function () {
    Livewire::test('pages::compliance.access-review')->set('notes', 'Done')->call('complete')->assertHasNoErrors();

    expect(AccessReview::query()->count())->toBe(1);

    Livewire::test('pages::compliance.access-review')->assertSee('Previous reviews')->assertSee(now()->format('Y-m-d'));
});

it('lets an auditor read the review but not complete it', function () {
    $auditor = User::factory()->withTwoFactor()->auditor()->create();
    $this->actingAs($auditor);

    $this->get(route('compliance.access-review'))->assertOk()->assertDontSee('Complete the review');
    expect(fn () => app(CompleteAccessReview::class)->handle(null, $auditor))->toThrow(AuthorizationException::class);

    $admin = User::factory()->withTwoFactor()->admin()->create();
    $this->actingAs($admin);
    $this->get(route('compliance.access-review'))->assertOk()->assertDontSee('Complete the review');
    expect(fn () => app(CompleteAccessReview::class)->handle(null, $admin))->toThrow(AuthorizationException::class);
});

it('requires an expiry within the policy when issuing a token', function () {
    app(VaultSettings::class)->update(['policy.token_max_lifetime_days' => 30], $this->owner);
    $project = Project::factory()->create();
    $issue = fn (?CarbonImmutable $expiresAt) => app(IssueAccessToken::class)->handle('ci', [$project->id], null, $expiresAt, $this->owner);

    expect(fn () => $issue(null))->toThrow(TokenPolicyException::class, 'within 30 days')
        ->and(fn () => $issue(CarbonImmutable::now()->addDays(31)))->toThrow(TokenPolicyException::class)
        ->and(AccessToken::query()->count())->toBe(0);

    $issue(CarbonImmutable::now()->addDays(30));
    $issue(CarbonImmutable::now()->addDays(5));

    expect(AccessToken::query()->count())->toBe(2);
});

it('does not limit tokens without a policy', function () {
    $project = Project::factory()->create();

    app(IssueAccessToken::class)->handle('ci', [$project->id], null, null, $this->owner);

    expect(AccessToken::query()->count())->toBe(1);
});

it('applies the policy when the expiry of a token changes and when it is regenerated', function () {
    $token = AccessToken::factory()->create(['expires_at' => null]);
    $project = Project::factory()->create();
    app(VaultSettings::class)->update(['policy.token_max_lifetime_days' => 30], $this->owner);

    expect(fn () => app(RegenerateAccessToken::class)->handle($token))->toThrow(TokenPolicyException::class)
        ->and(fn () => app(UpdateAccessToken::class)->handle($token, 'renamed', [$project->id], null, CarbonImmutable::now()->addDays(90)))->toThrow(TokenPolicyException::class);

    app(UpdateAccessToken::class)->handle($token, 'renamed', [$project->id], null, null)->refresh();
    expect($token->fresh()->name)->toBe('renamed');

    app(UpdateAccessToken::class)->handle($token, 'renamed', [$project->id], null, CarbonImmutable::now()->addDays(10));
    app(RegenerateAccessToken::class)->handle($token->fresh());

    expect($token->fresh()->isRevoked())->toBeFalse();
});

it('shows the policy message on the token form', function () {
    app(VaultSettings::class)->update(['policy.token_max_lifetime_days' => 30], $this->owner);
    $project = Project::factory()->create();

    Livewire::test('pages::tokens.create')->set('name', 'ci')->set('projectIds', [$project->id])->set('expiresOn', '')->call('save')->assertHasErrors('expiresOn');
    Livewire::test('pages::tokens.create')->set('name', 'ci')->set('projectIds', [$project->id])->set('expiresOn', now()->addDays(40)->toDateString())->call('save')->assertHasErrors('expiresOn');
    Livewire::test('pages::tokens.create')->set('name', 'ci')->set('projectIds', [$project->id])->set('expiresOn', now()->addDays(10)->toDateString())->call('save')->assertHasNoErrors();
});

it('warns when the last review is older than the interval', function () {
    $check = fn (): string => app(AccessReviewCheck::class)->run()->status->value;

    expect($check())->toBe('ok');

    $this->travel(91)->days();
    expect($check())->toBe('warning');

    AccessReview::factory()->create(['completed_at' => now()]);
    expect($check())->toBe('ok');

    $this->travel(91)->days();
    expect($check())->toBe('warning');

    app(VaultSettings::class)->update(['policy.access_review_interval_days' => 120], $this->owner);
    expect($check())->toBe('ok');
});

it('saves the policies from the page', function () {
    Livewire::test('pages::settings.policies')
        ->set('tokenMaxLifetimeDays', '90')->set('credentialRotationDays', '180')->set('accessReviewIntervalDays', '60')
        ->call('save')->assertHasNoErrors();

    $settings = app(VaultSettings::class);

    expect($settings->tokenMaxLifetimeDays())->toBe(90)->and($settings->credentialRotationDays())->toBe(180)->and($settings->accessReviewIntervalDays())->toBe(60);

    Livewire::test('pages::settings.policies')->set('credentialRotationDays', '5')->call('save')->assertHasErrors('credentialRotationDays');

    $this->actingAs(User::factory()->withTwoFactor()->create(['role' => AdminRole::Admin]));
    $this->get(route('settings.policies'))->assertForbidden();
});
