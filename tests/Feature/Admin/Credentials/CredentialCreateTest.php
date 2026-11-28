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
    $this->project = Project::factory()->create(['name' => 'Webshop']);
});

function createCredentialPage(): Testable
{
    return Livewire::test('pages::credentials.create', ['project' => test()->project]);
}

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

it('creates a credential in the project through the action with an encrypted secret and audits it', function () {
    $component = fillCredentialForm(createCredentialPage())
        ->call('save')
        ->assertHasNoErrors();

    $credential = Credential::query()->sole();

    expect($credential->name)->toBe('Magento')
        ->and($credential->type)->toBe(CredentialType::HttpBasic)
        ->and($credential->host)->toBe('repo.magento.com')
        ->and($credential->username)->toBe('public-key')
        ->and($credential->revealSecret())->toBe('plaintext-secret-marker')
        ->and($credential->project_id)->toBe($this->project->id)
        ->and(json_encode(DB::table('credentials')->first()))->not->toContain('plaintext-secret-marker');

    $component->assertRedirect(route('credentials.edit', [$this->project, $credential]));

    $entry = AuditEntry::query()->where('event', 'credential_created')->sole();

    expect($entry->subject_id)->toBe($credential->id)
        ->and(json_encode($entry->properties))->not->toContain('plaintext-secret-marker')
        ->and(session('flash'))->toBe('Credential created.');
});

it('never renders the secret and never keeps it in the component after saving', function () {
    $component = fillCredentialForm(createCredentialPage());

    // Livewire's set() makes a round trip, which a browser does not do for a deferred wire:model, so only the HTML is
    // checked while the secret is being typed. The input never echoes its value.
    expect($component->html())->not->toContain('plaintext-secret-marker');

    $component->call('save')->assertHasNoErrors()->assertSet('secret', '');

    expectNoSecret($component);
});

it('clears the secret when the form is rejected', function () {
    $component = fillCredentialForm(createCredentialPage(), ['name' => ''])
        ->call('save')
        ->assertHasErrors(['name'])
        ->assertSet('secret', '');

    expectNoSecret($component);
    expect(Credential::query()->count())->toBe(0);
});

it('requires a secret on create', function () {
    fillCredentialForm(createCredentialPage(), ['secret' => ''])
        ->call('save')
        ->assertHasErrors(['secret']);

    expect(Credential::query()->count())->toBe(0);
});

it('uses a password input that does not autocomplete', function () {
    $html = createCredentialPage()->html();

    expect($html)->toMatch('/<input[^>]*type="password"[^>]*autocomplete="new-password"|<input[^>]*autocomplete="new-password"[^>]*type="password"/');
});

it('requires a username for http-basic only', function (string $type, bool $requiresUsername) {
    $component = fillCredentialForm(createCredentialPage(), ['type' => $type, 'username' => ''])->call('save');

    $requiresUsername ? $component->assertHasErrors(['username']) : $component->assertHasNoErrors();
    expect(Credential::query()->count())->toBe($requiresUsername ? 0 : 1);
})->with([
    'http-basic' => ['http-basic', true],
    'bearer' => ['bearer', false],
    'github-oauth' => ['github-oauth', false],
    'gitlab-token' => ['gitlab-token', false],
]);

it('shows the username field only for types that need one', function () {
    createCredentialPage()
        ->set('type', 'http-basic')
        ->assertSee('Username')
        ->set('type', 'bearer')
        ->assertDontSee('Username');
});

it('drops a username that was typed before the type changed to one without a username', function () {
    fillCredentialForm(createCredentialPage(), ['type' => 'bearer', 'username' => 'leftover'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Credential::query()->sole()->username)->toBeNull();
});

it('rejects an unknown type', function () {
    fillCredentialForm(createCredentialPage(), ['type' => 'basic-auth'])
        ->call('save')
        ->assertHasErrors(['type']);
});

it('normalizes the host and rejects invalid hosts', function () {
    fillCredentialForm(createCredentialPage(), ['host' => 'https://Repo.Magento.com/'])
        ->call('save')
        ->assertHasNoErrors();

    expect(Credential::query()->sole()->host)->toBe('repo.magento.com');
});

it('rejects hosts with a port, spaces or nothing', function (string $host) {
    fillCredentialForm(createCredentialPage(), ['host' => $host])
        ->call('save')
        ->assertHasErrors(['host']);

    expect(Credential::query()->count())->toBe(0);
})->with(['repo.magento.com:443', 'repo magento.com', '', 'user:pass@example.com']);

it('shows a host conflict within the project on the host field and creates nothing', function () {
    Credential::factory()->for($this->project)->forHost('repo.magento.com')->create();

    fillCredentialForm(createCredentialPage())
        ->call('save')
        ->assertHasErrors(['host'])
        ->assertSet('secret', '');

    expect(Credential::query()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('accepts a host that another project holds', function () {
    Credential::factory()->forHost('repo.magento.com')->create();

    fillCredentialForm(createCredentialPage())->call('save')->assertHasNoErrors();

    expect(Credential::query()->count())->toBe(2)
        ->and($this->project->credentials()->count())->toBe(1);
});

it('renders the create page', function () {
    $this->get(route('credentials.create', $this->project))->assertOk()->assertSeeLivewire('pages::credentials.create');
});
