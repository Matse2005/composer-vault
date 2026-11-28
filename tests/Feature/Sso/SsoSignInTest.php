<?php

use App\Models\AuditEntry;
use App\Models\User;
use Firebase\JWT\JWT;
use Tests\Support\FakeOidcProvider;

beforeEach(function () {
    $this->idp = (new FakeOidcProvider)->install();
});

it('sends the browser to the provider with state, nonce and a PKCE challenge', function () {
    $started = startSso();
    parse_str((string) parse_url($started['url'], PHP_URL_QUERY), $query);

    expect($started['url'])->toStartWith('https://idp.example.test/authorize?')
        ->and($query['response_type'])->toBe('code')
        ->and($query['client_id'])->toBe('vault-client')
        ->and($query['redirect_uri'])->toBe(route('sso.callback'))
        ->and($query['scope'])->toBe('openid email profile')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and(strlen($query['code_challenge']))->toBe(43)
        ->and(strlen($query['state']))->toBeGreaterThan(30)
        ->and($query['state'])->not->toBe($query['nonce']);
});

it('signs an existing admin in, links the account and audits both', function () {
    $user = User::factory()->admin()->create(['email' => 'person@example.test']);

    finishSso()->assertRedirect(route('dashboard'));

    $user->refresh();

    expect(auth()->id())->toBe($user->id)
        ->and($user->sso_issuer)->toBe('https://idp.example.test')
        ->and($user->sso_subject)->toBe('subject-1')
        ->and($user->last_login_at)->not->toBeNull()
        ->and(AuditEntry::query()->where('event', 'sso_account_linked')->count())->toBe(1)
        ->and(AuditEntry::query()->where('event', 'admin_logged_in')->sole()->properties['method'])->toBe('sso');
});

it('sends the code with the PKCE verifier and the client credentials', function () {
    User::factory()->admin()->create(['email' => 'person@example.test']);

    finishSso();

    $request = $this->idp->lastTokenRequest;

    expect($request['grant_type'])->toBe('authorization_code')
        ->and($request['code'])->toBe('auth-code')
        ->and($request['redirect_uri'])->toBe(route('sso.callback'))
        ->and(strlen($request['code_verifier']))->toBeGreaterThanOrEqual(43)
        ->and($request['client_id'])->toBe('vault-client')
        ->and($request['client_secret'])->toBe('client-secret-value');
});

it('shows the same failure page and audits a reason for every rejection', function (array $claims, string $reason) {
    User::factory()->admin()->create(['email' => 'person@example.test']);

    finishSso($claims)->assertForbidden()->assertSee('Sign-in failed');

    expect(auth()->check())->toBeFalse()->and(lastRejection())->toBe($reason);
})->with([
    'wrong issuer' => [['iss' => 'https://evil.example.test'], 'invalid_id_token'],
    'wrong audience' => [['aud' => 'someone-else'], 'invalid_id_token'],
    'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200], 'invalid_id_token'],
    'issued in the future' => [['iat' => time() + 3600, 'exp' => time() + 7200], 'invalid_id_token'],
    'unverified email' => [['email_verified' => false], 'email_unverified'],
    'no email_verified claim' => [['email_verified' => null], 'email_unverified'],
    'no email' => [['email' => null], 'email_missing'],
    'no subject' => [['sub' => null], 'invalid_id_token'],
]);

it('rejects a wrong nonce', function () {
    finishSso(useNonce: false)->assertForbidden();

    expect(lastRejection())->toBe('invalid_id_token');
});

it('rejects a wrong state', function () {
    finishSso(state: 'not-the-state')->assertForbidden();

    expect(lastRejection())->toBe('state_mismatch');
});

it('rejects a callback without a started sign-in', function () {
    $this->get(route('sso.callback', ['code' => 'x', 'state' => 'y']))->assertForbidden();

    expect(lastRejection())->toBe('state_missing');
});

it('uses the state once', function () {
    User::factory()->admin()->create(['email' => 'person@example.test']);
    $started = startSso();
    $this->idp->nextIdToken = $this->idp->idToken($started['nonce']);
    $query = ['code' => 'c', 'state' => $started['state']];

    $this->get(route('sso.callback', $query))->assertRedirect();
    auth()->logout();
    $this->get(route('sso.callback', $query))->assertForbidden();

    expect(lastRejection())->toBe('state_missing');
});

it('rejects an expired state', function () {
    $started = startSso();
    $this->idp->nextIdToken = $this->idp->idToken($started['nonce']);
    $this->travel(11)->minutes();

    $this->get(route('sso.callback', ['code' => 'c', 'state' => $started['state']]))->assertForbidden();

    expect(lastRejection())->toBe('state_expired');
});

it('rejects an error from the provider', function () {
    $started = startSso();

    $this->get(route('sso.callback', ['error' => 'access_denied', 'state' => $started['state']]))->assertForbidden();

    expect(lastRejection())->toBe('provider_error');
});

it('rejects a failed code exchange', function () {
    $started = startSso();
    $this->idp->nextIdToken = null;

    $this->get(route('sso.callback', ['code' => 'bad', 'state' => $started['state']]))->assertForbidden();

    expect(lastRejection())->toBe('token_exchange_failed');
});

it('rejects a token with a bad signature', function () {
    User::factory()->admin()->create(['email' => 'person@example.test']);
    $started = startSso();
    $this->idp->nextIdToken = $this->idp->idToken($started['nonce'], signingKey: FakeOidcProvider::newPrivateKey());

    $this->get(route('sso.callback', ['code' => 'c', 'state' => $started['state']]))->assertForbidden();

    expect(lastRejection())->toBe('invalid_id_token');
});

it('rejects an unsigned token and a token signed with a shared secret', function (string $kind) {
    $started = startSso();
    $claims = ['iss' => FakeOidcProvider::ISSUER, 'aud' => FakeOidcProvider::CLIENT_ID, 'sub' => 's', 'iat' => time(), 'exp' => time() + 60, 'nonce' => $started['nonce'], 'email' => 'person@example.test', 'email_verified' => true];

    $this->idp->nextIdToken = $kind === 'none'
        ? rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT","kid":"test-key-1"}'), '+/', '-_'), '=').'.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.'
        : JWT::encode($claims, str_repeat('s', 40), 'HS256', 'test-key-1');

    $this->get(route('sso.callback', ['code' => 'c', 'state' => $started['state']]))->assertForbidden();

    expect(lastRejection())->toBe('invalid_id_token');
})->with(['none', 'hmac']);

it('rejects a disallowed email domain by exact match', function (string $email, bool $allowed) {
    config(['vault.sso.allowed_email_domains' => 'Example.test, other.test']);
    User::factory()->admin()->create(['email' => $email]);

    $response = finishSso(['email' => $email]);

    $allowed ? $response->assertRedirect() : $response->assertForbidden();
    expect($allowed ? lastRejection() : lastRejection())->toBe($allowed ? null : 'domain_not_allowed');
})->with([
    'listed' => ['a@example.test', true],
    'second listed' => ['a@other.test', true],
    'unlisted' => ['a@evil.test', false],
    'suffix only' => ['a@notexample.test', false],
    'subdomain' => ['a@sub.example.test', false],
]);

it('resolves the highest role from the groups claim', function () {
    config(['vault.sso.role_groups.owner' => 'vault-owners', 'vault.sso.role_groups.admin' => 'vault-admins, devs', 'vault.sso.role_groups.auditor' => 'auditors']);
    $user = User::factory()->withTwoFactor()->create(['email' => 'person@example.test']);
    User::factory()->create();

    finishSso(['groups' => ['everyone', 'auditors', 'devs']])->assertRedirect();

    expect($user->fresh()->role->value)->toBe('admin');
    $entry = AuditEntry::query()->where('event', 'admin_role_changed')->sole();
    expect($entry->properties->toArray())->toEqual(['old_role' => 'owner', 'new_role' => 'admin', 'source' => 'sso'])
        ->and($entry->causer_id)->toBeNull();
});

it('rejects a person without a matching group when a mapping exists', function () {
    config(['vault.sso.role_groups.admin' => 'vault-admins']);
    User::factory()->admin()->create(['email' => 'person@example.test']);

    finishSso(['groups' => ['other']])->assertForbidden();

    expect(lastRejection())->toBe('no_role');
});

it('keeps the role when no mapping is configured', function () {
    $user = User::factory()->withTwoFactor()->auditor()->create(['email' => 'person@example.test']);

    finishSso(['groups' => ['anything']])->assertRedirect();

    expect($user->fresh()->role->value)->toBe('auditor');
});

it('never demotes the last active owner through the provider', function () {
    config(['vault.sso.role_groups.auditor' => 'auditors']);
    $owner = User::factory()->withTwoFactor()->create(['email' => 'person@example.test']);

    finishSso(['groups' => ['auditors']])->assertRedirect();

    expect($owner->fresh()->role->value)->toBe('owner');
});

it('refuses an unknown person unless provisioning is on and a group gives a role', function () {
    finishSso()->assertForbidden();
    expect(lastRejection())->toBe('not_provisioned')->and(User::query()->count())->toBe(0);

    config(['vault.sso.auto_provision' => true]);
    finishSso()->assertForbidden();
    expect(lastRejection())->toBe('not_provisioned')->and(User::query()->count())->toBe(0);
});

it('provisions an admin with the role of the group and no usable password', function () {
    config(['vault.sso.auto_provision' => true, 'vault.sso.role_groups.auditor' => 'auditors']);

    finishSso(['groups' => ['auditors']])->assertRedirect();

    $user = User::query()->sole();

    expect($user->email)->toBe('person@example.test')
        ->and($user->name)->toBe('Person Example')
        ->and($user->role->value)->toBe('auditor')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->sso_subject)->toBe('subject-1')
        ->and(Hash::check('', $user->password))->toBeFalse()
        ->and(AuditEntry::query()->where('event', 'admin_provisioned')->count())->toBe(1);
});

it('refuses a deactivated person by every route', function () {
    User::factory()->admin()->create(['email' => 'person@example.test', 'deactivated_at' => now()]);

    finishSso()->assertForbidden();
    expect(lastRejection())->toBe('deactivated');

    User::query()->update(['sso_issuer' => 'https://idp.example.test', 'sso_subject' => 'subject-1']);

    finishSso()->assertForbidden();
    expect(lastRejection())->toBe('deactivated')->and(auth()->check())->toBeFalse();
});

it('does not link an account that belongs to another identity', function () {
    User::factory()->admin()->create(['email' => 'person@example.test', 'sso_issuer' => 'https://idp.example.test', 'sso_subject' => 'someone-else']);

    finishSso()->assertForbidden();

    expect(lastRejection())->toBe('not_provisioned');
});

it('sends an admin with an authenticator app to the local challenge unless the provider vouches for mfa', function () {
    config(['vault.sso.trust_idp_mfa' => true]);
    $user = User::factory()->withTwoFactor()->admin()->create(['email' => 'person@example.test']);

    finishSso(['amr' => ['mfa']])->assertRedirect(route('dashboard'));
    expect(auth()->id())->toBe($user->id);
    auth()->logout();
    session()->flush();

    finishSso(['amr' => ['pwd']])->assertRedirect(route('two-factor.login'));
    expect(auth()->check())->toBeFalse()->and(session('login.id'))->toBe($user->id);
});

it('trusts the mfa claim of the provider only when told to', function () {
    config(['vault.sso.trust_idp_mfa' => true]);
    $user = User::factory()->admin()->create(['email' => 'person@example.test']);

    finishSso(['amr' => ['pwd', 'mfa']])->assertRedirect(route('dashboard'));

    $this->get(route('projects.index'))->assertOk();
    expect(auth()->id())->toBe($user->id);
});

it('accepts a configured acr value as mfa', function () {
    config(['vault.sso.trust_idp_mfa' => true, 'vault.sso.mfa_acr_values' => 'urn:mfa, gold']);
    User::factory()->admin()->create(['email' => 'person@example.test']);

    finishSso(['acr' => 'gold'])->assertRedirect(route('dashboard'));

    $this->get(route('projects.index'))->assertOk();
});

it('asks an admin without any second factor to set one up', function () {
    User::factory()->admin()->create(['email' => 'person@example.test']);

    finishSso()->assertRedirect(route('dashboard'));

    $this->get(route('projects.index'))->assertRedirect(route('security.edit'));
});

it('refuses a passkey-only admin without mfa from the provider', function () {
    $user = User::factory()->admin()->create(['email' => 'person@example.test']);
    DB::table('passkeys')->insert(['user_id' => $user->id, 'name' => 'Key', 'credential_id' => 'cred-1', 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    finishSso()->assertForbidden();

    expect(lastRejection())->toBe('second_factor_required');
});

it('is a 404 when single sign-on is off or incomplete', function () {
    config(['vault.sso.enabled' => false]);
    $this->get(route('sso.redirect'))->assertNotFound();
    $this->get(route('sso.callback'))->assertNotFound();

    config(['vault.sso.enabled' => true, 'vault.sso.client_secret' => '']);
    $this->get(route('sso.redirect'))->assertNotFound();
});

it('shows the button on the login page only when enabled', function () {
    $this->get(route('login'))->assertOk()->assertSee('Sign in with SSO');

    config(['vault.sso.enabled' => false]);
    $this->get(route('login'))->assertOk()->assertDontSee('Sign in with SSO');
});

it('is a 404 when discovery fails and is rejected quietly', function () {
    $this->idp->metadata = [];

    $this->get(route('sso.redirect'))->assertForbidden();

    expect(lastRejection())->toBe('discovery_failed');
});

it('refuses metadata whose issuer differs from the configured one', function () {
    $this->idp->metadata['issuer'] = 'https://other.example.test';

    $this->get(route('sso.redirect'))->assertForbidden();

    expect(lastRejection())->toBe('discovery_failed');
});

it('rate limits the single sign-on routes', function () {
    foreach (range(1, 10) as $ignored) {
        $this->get(route('sso.redirect'))->assertRedirect();
    }

    $this->get(route('sso.redirect'))->assertStatus(429);
});

it('redirects to the end session endpoint after logging out of a single sign-on session', function () {
    User::factory()->admin()->create(['email' => 'person@example.test']);
    finishSso(['amr' => ['mfa']]);

    $response = $this->post(route('logout'));

    $location = $response->headers->get('Location');
    expect($location)->toStartWith('https://idp.example.test/logout?')
        ->and($location)->toContain('post_logout_redirect_uri='.rawurlencode(route('login')))
        ->and($location)->not->toContain('id_token_hint');
});

it('logs out locally as usual for a password session', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());

    $this->post(route('logout'))->assertRedirect('/');
});

it('keeps tokens, claims and the secret out of the session, the audit log and the logs', function () {
    config(['vault.sso.auto_provision' => true, 'vault.sso.role_groups.admin' => 'admins']);
    $started = startSso();
    $token = $this->idp->idToken($started['nonce'], ['groups' => ['admins']]);
    $this->idp->nextIdToken = $token;

    $this->get(route('sso.callback', ['code' => 'auth-code', 'state' => $started['state']]))->assertRedirect();

    $stored = json_encode([
        session()->all(),
        DB::table('activity_log')->get(),
        DB::table('users')->get(),
        DB::table('sessions')->get(),
    ]);

    foreach ([$token, 'access-token-value', 'refresh-token-value', 'client-secret-value'] as $secret) {
        expect($stored)->not->toContain($secret);
    }

    expect(session()->has('vault.sso.authorization'))->toBeFalse();
});
