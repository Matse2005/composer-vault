<?php

use App\Enums\AccessResult;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\User;
use App\Vault\Tokens\TokenAuthenticator;
use Database\Factories\AccessTokenFactory;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->actingAs($this->admin);
    $this->project = Project::factory()->create(['name' => 'Webshop', 'slug' => 'webshop']);
    $this->token = AccessToken::factory()->for($this->project)->withPlainToken()->withAllowedIps(['203.0.113.7'])->create(['name' => 'prod-web-01']);
    $this->plain = AccessTokenFactory::plainTokenFor($this->token);
});

function showToken(AccessToken $token): Testable
{
    return Livewire::test('pages::tokens.show', ['project' => $token->project_id, 'accessToken' => $token]);
}

it('shows the current settings', function () {
    showToken($this->token)
        ->assertSet('name', 'prod-web-01')
        ->assertSet('allowedIps', '203.0.113.7')
        ->assertSet('expiresOn', '')
        ->assertSee('cv_'.$this->token->token_prefix.'...')
        ->assertSee('Active');
});

it('never shows the hash or the plain token on the page', function () {
    $component = showToken($this->token);

    foreach ([$component->html(), json_encode($component->snapshot)] as $output) {
        expect($output)->not->toContain($this->token->token_hash)->not->toContain($this->plain);
    }
});

it('updates the settings through the action and audits the change', function () {
    showToken($this->token)
        ->set('name', 'prod-web-02')
        ->set('allowedIps', "10.0.0.0/8\n198.51.100.1")
        ->set('expiresOn', now()->addWeek()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $this->token->fresh();

    expect($fresh->name)->toBe('prod-web-02')
        ->and($fresh->allowed_ips)->toBe(['10.0.0.0/8', '198.51.100.1'])
        ->and($fresh->expires_at->toDateString())->toBe(now()->addWeek()->toDateString())
        ->and($fresh->project_id)->toBe($this->project->id)
        ->and($fresh->token_hash)->toBe($this->token->token_hash)
        ->and(AuditEntry::query()->where('event', 'token_updated')->count())->toBe(1)
        ->and(session('flash'))->toBe('Token saved.');
});

it('rejects invalid settings and changes nothing', function (string $field, mixed $value) {
    showToken($this->token)->set($field, $value)->call('save')->assertHasErrors([$field]);

    expect($this->token->fresh()->only(['name', 'allowed_ips']))->toEqual(['name' => 'prod-web-01', 'allowed_ips' => ['203.0.113.7']])
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'no name' => ['name', ''],
    'bad ip line' => ['allowedIps', "203.0.113.7\nnot-an-ip"],
    'past expiry' => ['expiresOn', '2020-01-01'],
]);

it('regenerates the token, shows the new value once and invalidates the old one', function () {
    $component = showToken($this->token)->call('regenerate')->assertHasNoErrors();

    $newPlain = $component->get('plainToken');
    $fresh = $this->token->fresh();

    expect($newPlain)->toMatch('/^cv_[A-Za-z0-9]{40}$/')->not->toBe($this->plain)
        ->and($fresh->token_hash)->toBe(hash('sha256', $newPlain));

    $component->assertSee($newPlain)->assertSee('COMPOSER_VAULT_TOKEN='.$newPlain)->assertSee('This token is shown once. Store it now.');

    $authenticator = app(TokenAuthenticator::class);

    expect($authenticator->authenticate($this->plain, '203.0.113.7')->result)->toBe(AccessResult::UnknownToken)
        ->and($authenticator->authenticate($newPlain, '203.0.113.7')->result)->toBe(AccessResult::Ok)
        ->and(AuditEntry::query()->where('event', 'token_regenerated')->count())->toBe(1);

    $component->call('closeTokenModal')->assertSet('plainToken', null);

    foreach ([$component->html(), json_encode($component->snapshot)] as $output) {
        expect($output)->not->toContain($newPlain);
    }
});

it('does not contain the regenerated token on a fresh page load', function () {
    $newPlain = showToken($this->token)->call('regenerate')->get('plainToken');

    $this->get(route('tokens.show', [$this->project, $this->token]))->assertOk()->assertDontSee($newPlain);
});

it('refuses to regenerate a revoked token', function () {
    $this->token->forceFill(['revoked_at' => now()])->save();

    showToken($this->token)->call('regenerate')->assertHasErrors(['token'])->assertSet('plainToken', null);

    expect($this->token->fresh()->token_hash)->toBe($this->token->token_hash)
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('revokes the token and audits it', function () {
    showToken($this->token)->call('revoke')->assertHasNoErrors()->assertRedirect(route('tokens.show', [$this->project, $this->token]));

    expect($this->token->fresh()->isRevoked())->toBeTrue()
        ->and(session('flash'))->toBe('Token revoked.')
        ->and(AuditEntry::query()->where('event', 'token_revoked')->count())->toBe(1)
        ->and(app(TokenAuthenticator::class)->authenticate($this->plain, '203.0.113.7')->result)->toBe(AccessResult::Revoked);
});

it('deletes the token and audits it', function () {
    showToken($this->token)->call('delete')->assertRedirect(route('projects.show', $this->project));

    expect(AccessToken::query()->count())->toBe(0)
        ->and(AuditEntry::query()->where('event', 'token_deleted')->count())->toBe(1)
        ->and(session('flash'))->toBe('Token deleted.');
});

it('explains regenerate, revoke and delete in their confirmation modals', function () {
    showToken($this->token)
        ->assertSee('old value stops working at once')
        ->assertSee('stops working on its next request')
        ->assertSee('cannot be undone');
});

it('shows the 20 most recent access log rows of this token', function () {
    foreach (range(1, 22) as $minutesAgo) {
        AccessLog::factory()->create([
            'access_token_id' => $this->token->id,
            'project_id' => $this->project->id,
            'result' => AccessResult::Ok,
            'ip' => "198.51.100.{$minutesAgo}",
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }
    AccessLog::factory()->create(['ip' => '192.0.2.99']);

    $component = showToken($this->token);

    expect($component->get('recentLogs'))->toHaveCount(20)
        ->and($component->get('recentLogs')->first()->ip)->toBe('198.51.100.1');

    $component->assertSee('198.51.100.20')->assertDontSee('198.51.100.21')->assertDontSee('192.0.2.99');
});

it('cannot be pointed at another token through the locked id', function () {
    $other = AccessToken::factory()->create();

    expect(fn () => showToken($this->token)->set('tokenId', $other->id))->toThrow(Exception::class);
});

it('gives a 404 for a token that does not exist', function () {
    $this->get(route('tokens.show', [$this->project, '01HZZZZZZZZZZZZZZZZZZZZZZZ']))->assertNotFound();
});

it('renders the show page', function () {
    $this->get(route('tokens.show', [$this->project, $this->token]))->assertOk()->assertSeeLivewire('pages::tokens.show');
});
