<?php

use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->actingAs($this->admin);
    $this->project = Project::factory()->create(['name' => 'Webshop', 'slug' => 'webshop']);
});

function createToken(array $values = []): Testable
{
    $values += ['name' => 'prod-web-01', 'projectIds' => [test()->project->id]];
    $component = Livewire::test('pages::tokens.create');

    foreach ($values as $field => $value) {
        $component->set($field, $value);
    }

    return $component->call('save');
}

it('issues a token through the action with the settings and audits it', function () {
    $component = createToken([
        'allowedIps' => "203.0.113.7\n10.0.0.0/8\n2001:db8::/32",
        'expiresOn' => now()->addMonth()->toDateString(),
    ])->assertHasNoErrors();

    $token = AccessToken::query()->sole();

    expect($token->name)->toBe('prod-web-01')
        ->and($token->allowed_ips)->toBe(['203.0.113.7', '10.0.0.0/8', '2001:db8::/32'])
        ->and($token->expires_at->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and($token->expires_at->format('H:i:s'))->toBe('23:59:59')
        ->and($token->created_by)->toBe($this->admin->id)
        ->and($token->projects()->pluck('projects.id')->all())->toBe([$this->project->id]);

    $entry = AuditEntry::query()->where('event', 'token_issued')->sole();

    expect($entry->subject_id)->toBe($token->id)
        ->and((string) $entry->causer_id)->toBe((string) $this->admin->id);
});

it('shows the plain token once in a modal that cannot be dismissed by clicking outside', function () {
    $component = createToken();

    $plain = $component->get('plainToken');
    $token = AccessToken::query()->sole();

    expect($plain)->toMatch('/^cv_[A-Za-z0-9]{40}$/')
        ->and($token->token_hash)->toBe(hash('sha256', $plain));

    $component->assertSee($plain)
        ->assertSee('COMPOSER_VAULT_TOKEN='.$plain)
        ->assertSee('This token is shown once. Store it now.');

    expect($component->html())->toContain('disable-click-outside')->toContain('disable-escape')
        ->and($component->html())->not->toContain($token->token_hash);
});

it('does not store the plain token anywhere', function () {
    $plain = createToken()->get('plainToken');

    expect(json_encode(DB::table('access_tokens')->get()))->not->toContain($plain)
        ->and(json_encode(DB::table('activity_log')->get()))->not->toContain($plain)
        ->and(json_encode(DB::table('access_logs')->get()))->not->toContain($plain);
});

it('forgets the token when the modal is closed and goes to the token', function () {
    $component = createToken();
    $token = AccessToken::query()->sole();

    $component->call('closeTokenModal')
        ->assertSet('plainToken', null)
        ->assertSet('tokenId', null)
        ->assertRedirect(route('tokens.show', $token));

    expect(session('flash'))->toBe('Token created.');
});

it('does not contain the token on a fresh page load or on the token page', function () {
    $plain = createToken()->get('plainToken');
    $token = AccessToken::query()->sole();

    $this->get(route('tokens.create'))->assertOk()->assertDontSee($plain);
    $this->get(route('tokens.show', $token))->assertOk()->assertDontSee($plain)->assertDontSee($token->token_hash);
    $this->get(route('tokens.index'))->assertOk()->assertDontSee($plain);
});

it('cannot be given a plain token from the client', function () {
    expect(fn () => Livewire::test('pages::tokens.create')->set('plainToken', 'cv_'.str_repeat('a', 40)))->toThrow(Exception::class);
});

it('requires a name and at least one project', function (array $values, string $field) {
    createToken($values)->assertHasErrors([$field]);

    expect(AccessToken::query()->count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'name too long' => [['name' => str_repeat('a', 101)], 'name'],
    'no project' => [['projectIds' => []], 'projectIds'],
]);

it('rejects a project that does not exist', function () {
    createToken(['projectIds' => ['01HZZZZZZZZZZZZZZZZZZZZZZZ']])->assertHasErrors(['projectIds.0']);
});

it('names the line of an invalid allowlist entry', function (string $text, string $message) {
    createToken(['allowedIps' => $text])->assertHasErrors(['allowedIps'])->assertSee($message);

    expect(AccessToken::query()->count())->toBe(0);
})->with([
    'second line' => ["203.0.113.7\n10.0.0.0/33", 'Line 2'],
    'hostname' => ["hello\n203.0.113.7", 'Line 1'],
    'wildcard' => ["203.0.113.7\n\n*", 'Line 3'],
]);

it('ignores blank lines and surrounding spaces in the allowlist', function () {
    createToken(['allowedIps' => "\n  203.0.113.7  \n\n10.0.0.0/8\n"])->assertHasNoErrors();

    expect(AccessToken::query()->sole()->allowed_ips)->toBe(['203.0.113.7', '10.0.0.0/8']);
});

it('stores no restriction for an empty allowlist', function () {
    createToken(['allowedIps' => " \n "])->assertHasNoErrors();

    expect(AccessToken::query()->sole()->allowed_ips)->toBeNull();
});

it('requires an expiry date in the future', function () {
    createToken(['expiresOn' => now()->subDay()->toDateString()])->assertHasErrors(['expiresOn']);
    createToken(['expiresOn' => 'not a date'])->assertHasErrors(['expiresOn']);

    expect(AccessToken::query()->count())->toBe(0);
});

it('accepts an expiry date of today and of tomorrow', function () {
    createToken(['expiresOn' => now()->toDateString()])->assertHasNoErrors();
    createToken(['name' => 'second', 'expiresOn' => now()->addDay()->toDateString()])->assertHasNoErrors();

    expect(AccessToken::query()->count())->toBe(2);
});

it('keeps the expiry optional', function () {
    createToken()->assertHasNoErrors();

    expect(AccessToken::query()->sole()->expires_at)->toBeNull();
});

it('renders the create page with a native date input', function () {
    $this->get(route('tokens.create'))
        ->assertOk()
        ->assertSeeLivewire('pages::tokens.create')
        ->assertSee('type="date"', false);
});
