<?php

use App\Models\AuditEntry;
use App\Models\User;
use App\Vault\Settings\VaultSettings;
use App\Vault\Sso\SsoConfig;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FakeOidcProvider;

beforeEach(function () {
    $this->owner = User::factory()->withTwoFactor()->create();
    $this->actingAs($this->owner);
});

it('is off and incomplete until configured in the dashboard', function () {
    $sso = app(SsoConfig::class);

    expect($sso->enabled())->toBeFalse()->and($sso->isActive())->toBeFalse()
        ->and($sso->scopes())->toBe(['openid', 'email', 'profile'])
        ->and($sso->buttonLabel())->toBe('Sign in with SSO');
});

it('reads the dashboard values and the stored secret', function () {
    (new FakeOidcProvider)->fake();
    $settings = app(VaultSettings::class);
    $settings->update(['sso.enabled' => true, 'sso.issuer' => 'https://idp.example.test', 'sso.client_id' => 'abc'], $this->owner);
    $settings->setSecret('sso.client_secret', 'dashboard-secret', $this->owner);

    $sso = app(SsoConfig::class);

    expect($sso->isActive())->toBeTrue()
        ->and($sso->clientSecret())->toBe('dashboard-secret')
        ->and($sso->hasRoleMapping())->toBeFalse();
});

it('lets an environment value win over the dashboard and lock the field', function () {
    app(VaultSettings::class)->update(['sso.client_id' => 'from-dashboard'], $this->owner);
    config(['vault.sso.client_id' => 'from-env']);

    $settings = app(VaultSettings::class);

    expect(app(SsoConfig::class)->clientId())->toBe('from-env')
        ->and($settings->isFromEnvironment('sso.client_id'))->toBeTrue()
        ->and($settings->isFromEnvironment('sso.issuer'))->toBeFalse();

    config(['vault.sso.client_id' => null]);

    expect(app(SsoConfig::class)->clientId())->toBe('from-dashboard');
});

it('stores the secret encrypted, never in plain text and never in the audit entry', function () {
    app(VaultSettings::class)->setSecret('sso.client_secret', 'super-secret-value', $this->owner);

    $row = (string) DB::table('vault_settings')->where('key', 'sso.client_secret')->value('value');
    $entry = AuditEntry::query()->where('event', 'settings_changed')->sole();

    expect($row)->not->toContain('super-secret-value')
        ->and(json_encode($entry->properties))->not->toContain('super-secret-value')
        ->and($entry->properties->toArray())->toEqual(['changes' => ['sso.client_secret' => ['old' => 'changed', 'new' => 'changed']]])
        ->and(app(VaultSettings::class)->secret('sso.client_secret'))->toBe('super-secret-value');
});

it('binds the stored secret to its setting', function () {
    app(VaultSettings::class)->setSecret('sso.client_secret', 'super-secret-value', $this->owner);
    $stored = DB::table('vault_settings')->where('key', 'sso.client_secret')->value('value');
    DB::table('vault_settings')->insert(['key' => 'sso.other', 'value' => $stored]);

    expect(app(VaultSettings::class)->secret('sso.other'))->toBeNull();
});

it('clears a secret with an empty value', function () {
    $settings = app(VaultSettings::class);
    $settings->setSecret('sso.client_secret', 'x-secret', $this->owner);
    $settings->setSecret('sso.client_secret', '', $this->owner);

    expect($settings->hasSecret('sso.client_secret'))->toBeFalse();
});

it('keeps the secret readable when the key is rotated', function () {
    app(VaultSettings::class)->setSecret('sso.client_secret', 'rotate-me', $this->owner);
    $before = DB::table('vault_settings')->where('key', 'sso.client_secret')->value('value');

    $this->artisan('vault:rotate-key', ['--key' => vaultTestKey(2)])->assertSuccessful();

    config(['vault.key' => vaultTestKey(2), 'vault.key_version' => 2, 'vault.previous_keys' => '1:'.vaultTestKey(1)]);
    app()->forgetInstance(VaultSettings::class);

    expect(app(VaultSettings::class)->secret('sso.client_secret'))->toBe('rotate-me')
        ->and(DB::table('vault_settings')->where('key', 'sso.client_secret')->value('value'))->not->toBe($before);
});

it('refuses other roles on the settings page', function (string $role) {
    $this->actingAs(User::factory()->withTwoFactor()->create(['role' => $role]));

    $this->get(route('settings.sso'))->assertForbidden();
})->with(['admin', 'auditor']);

it('saves the settings and the secret from the page and empties the secret field', function () {
    (new FakeOidcProvider)->fake();

    Livewire::test('pages::settings.sso')
        ->set('form.issuer', 'https://idp.example.test')
        ->set('form.client_id', 'vault-client')
        ->set('form.enabled', true)
        ->set('clientSecret', 'page-secret')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('clientSecret', '')
        ->assertSee('A client secret is stored')
        ->assertDontSee('page-secret');

    $sso = app(SsoConfig::class);

    expect($sso->isActive())->toBeTrue()->and($sso->clientSecret())->toBe('page-secret')
        ->and($sso->isActive())->toBeTrue();
});

it('shows the validation message inline', function () {
    Livewire::test('pages::settings.sso')
        ->set('form.issuer', 'http://not-secure.example.test')
        ->call('save')
        ->assertHasErrors(['form.issuer']);
});

it('disables and ignores a field that the environment sets, even when the request is tampered with', function () {
    config(['vault.sso.client_id' => 'from-env']);

    Livewire::test('pages::settings.sso')
        ->assertSee('Set by the environment')
        ->set('form.client_id', 'tampered')
        ->call('save');

    expect(DB::table('vault_settings')->where('key', 'sso.client_id')->exists())->toBeFalse()
        ->and(app(SsoConfig::class)->clientId())->toBe('from-env');
});

it('never puts the stored secret in the page or the livewire state', function () {
    app(VaultSettings::class)->setSecret('sso.client_secret', 'hidden-secret-value', $this->owner);

    $component = Livewire::test('pages::settings.sso');

    expect($component->html())->not->toContain('hidden-secret-value')
        ->and(json_encode($component->snapshot ?? []))->not->toContain('hidden-secret-value');
});
