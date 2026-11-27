<?php

use App\Enums\CredentialType;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->actingAs($this->admin);
});

function fillCredentialForm(Testable $component, array $values = []): Testable
{
    $values += [
        'name' => 'Magento',
        'type' => 'http-basic',
        'host' => 'repo.magento.com',
        'username' => 'public-key',
        'secret' => 'plaintext-secret-marker',
    ];

    foreach ($values as $field => $value) {
        $component->set($field, $value);
    }

    return $component;
}

/**
 * Assert that neither the HTML nor the Livewire snapshot of the component holds the secret.
 */
function expectNoSecret(Testable $component, string $secret = 'plaintext-secret-marker'): void
{
    foreach ([$component->html(), json_encode($component->snapshot)] as $output) {
        expect($output)->not->toContain($secret);
    }
}

it('creates a credential through the action with an encrypted secret and audits it', function () {
    $project = Project::factory()->create();

    $component = fillCredentialForm(Livewire::test('pages::credentials.create'))
        ->set('projectIds', [$project->id])
        ->call('save')
        ->assertHasNoErrors();

    $credential = Credential::query()->sole();

    expect($credential->name)->toBe('Magento')
        ->and($credential->type)->toBe(CredentialType::HttpBasic)
        ->and($credential->host)->toBe('repo.magento.com')
        ->and($credential->username)->toBe('public-key')
        ->and($credential->revealSecret())->toBe('plaintext-secret-marker')
        ->and($credential->projects()->pluck('projects.id')->all())->toBe([$project->id])
        ->and(json_encode(DB::table('credentials')->first()))->not->toContain('plaintext-secret-marker');

    $component->assertRedirect(route('credentials.edit', $credential));

    $entry = AuditEntry::query()->where('event', 'credential_created')->sole();

    expect($entry->subject_id)->toBe($credential->id)
        ->and(json_encode($entry->properties))->not->toContain('plaintext-secret-marker')
        ->and(session('flash'))->toBe('Credential created.');
});

it('never renders the secret and never keeps it in the component after saving', function () {
    $component = fillCredentialForm(Livewire::test('pages::credentials.create'));

    // Livewire's set() makes a round trip, which a browser does not do for a deferred wire:model, so only the HTML is
    // checked while the secret is being typed. The input never echoes its value.
    expect($component->html())->not->toContain('plaintext-secret-marker');

    $component->call('save')->assertHasNoErrors()->assertSet('secret', '');

    expectNoSecret($component);
});

it('clears the secret when the form is rejected', function () {
    $component = fillCredentialForm(Livewire::test('pages::credentials.create'), ['name' => ''])
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSet('secret', '');

    expectNoSecret($component);
    expect(Credential::query()->count())->toBe(0);
});

it('requires a secret on create', function () {
    fillCredentialForm(Livewire::test('pages::credentials.create'), ['secret' => ''])
        ->call('save')
        ->assertHasErrors(['secret']);

    expect(Credential::query()->count())->toBe(0);
});

it('uses a password input that does not autocomplete', function () {
    $html = Livewire::test('pages::credentials.create')->html();

    expect($html)->toMatch('/<input[^>]*type="password"[^>]*autocomplete="new-password"|<input[^>]*autocomplete="new-password"[^>]*type="password"/');
});

it('requires a username for http-basic only', function (string $type, bool $requiresUsername) {
    $component = fillCredentialForm(Livewire::test('pages::credentials.create'), ['type' => $type, 'username' => ''])->call('save');

    $requiresUsername ? $component->assertHasErrors(['username']) : $component->assertHasNoErrors();
    expect(Credential::query()->count())->toBe($requiresUsername ? 0 : 1);
})->with([
    'http-basic' => ['http-basic', true],
    'bearer' => ['bearer', false],
    'github-oauth' => ['github-oauth', false],
    'gitlab-token' => ['gitlab-token', false],
]);

it('shows the username field only for types that need one', function () {
    Livewire::test('pages::credentials.create')
        ->set('type', 'http-basic')
        ->assertSee('Username')
        ->set('type', 'bearer')
        ->assertDontSee('Username');
});

it('drops a username that was typed before the type changed to one without a username', function () {
    fillCredentialForm(Livewire::test('pages::credentials.create'), ['type' => 'bearer', 'username' => 'leftover'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Credential::query()->sole()->username)->toBeNull();
});

it('rejects an unknown type', function () {
    fillCredentialForm(Livewire::test('pages::credentials.create'), ['type' => 'basic-auth'])
        ->call('save')
        ->assertHasErrors(['type']);
});

it('normalizes the host and rejects invalid hosts', function () {
    fillCredentialForm(Livewire::test('pages::credentials.create'), ['host' => 'https://Repo.Magento.com/'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Credential::query()->sole()->host)->toBe('repo.magento.com');
});

it('rejects hosts with a port, spaces or nothing', function (string $host) {
    fillCredentialForm(Livewire::test('pages::credentials.create'), ['host' => $host])
        ->call('save')
        ->assertHasErrors(['host']);

    expect(Credential::query()->count())->toBe(0);
})->with(['repo.magento.com:443', 'repo magento.com', '', 'user:pass@example.com']);

it('shows a host conflict on the projects field and creates nothing', function () {
    $project = Project::factory()->create(['name' => 'Webshop']);
    $project->credentials()->attach(Credential::factory()->forHost('repo.magento.com')->create());

    fillCredentialForm(Livewire::test('pages::credentials.create'))
        ->set('projectIds', [$project->id])
        ->call('save')
        ->assertHasErrors(['projectIds'])
        ->assertSet('secret', '');

    expect(Credential::query()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('rejects project ids that do not exist', function () {
    fillCredentialForm(Livewire::test('pages::credentials.create'))
        ->set('projectIds', ['01HZZZZZZZZZZZZZZZZZZZZZZZ'])
        ->call('save')
        ->assertHasErrors(['projectIds.0']);
});

it('renders the create page', function () {
    $this->get(route('credentials.create'))->assertOk()->assertSeeLivewire('pages::credentials.create');
});
