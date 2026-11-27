<?php

use App\Enums\CredentialType;
use App\Exceptions\Vault\DecryptionFailedException;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->actingAs($this->admin);
    $this->credential = Credential::factory()
        ->ofType(CredentialType::HttpBasic)
        ->forHost('repo.magento.com')
        ->withSecret('original-secret-marker')
        ->create(['name' => 'Magento', 'username' => 'public-key', 'notes' => 'Production']);
});

function editCredential(Credential $credential): Testable
{
    return Livewire::test('pages::credentials.edit', ['credential' => $credential]);
}

/**
 * @param  array<int, string>  $secrets
 */
function expectNoSecrets(Testable $component, array $secrets, Credential $credential): void
{
    foreach ([$component->html(), json_encode($component->snapshot)] as $output) {
        foreach ($secrets as $secret) {
            expect($output)->not->toContain($secret);
        }

        expect($output)->not->toContain($credential->secret_ciphertext)->not->toContain($credential->data_key_ciphertext);
    }
}

it('shows the current values but never the secret', function () {
    $component = editCredential($this->credential)
        ->assertSet('name', 'Magento')
        ->assertSet('type', 'http-basic')
        ->assertSet('host', 'repo.magento.com')
        ->assertSet('username', 'public-key')
        ->assertSet('notes', 'Production')
        ->assertSet('secret', '')
        ->assertSee('Leave empty to keep the current secret.')
        ->assertSee('Secret last changed');

    expectNoSecrets($component, ['original-secret-marker'], $this->credential);
});

it('keeps the old secret when the secret field is left empty', function () {
    $changedAt = $this->credential->secret_changed_at;
    $this->travel(5)->minutes();

    editCredential($this->credential)->set('name', 'Magento EU')->call('save')->assertHasNoErrors();

    $fresh = $this->credential->fresh();

    expect($fresh->name)->toBe('Magento EU')
        ->and($fresh->revealSecret())->toBe('original-secret-marker')
        ->and($fresh->secret_changed_at->toDateTimeString())->toBe($changedAt->toDateTimeString());

    expect(AuditEntry::query()->pluck('event')->all())->toBe(['credential_updated']);
});

it('replaces the secret when a new one is given and audits it without the value', function () {
    $this->travel(5)->minutes();

    $component = editCredential($this->credential)->set('secret', 'replacement-secret-marker')->call('save')->assertHasNoErrors();

    $fresh = $this->credential->fresh();

    expect($fresh->revealSecret())->toBe('replacement-secret-marker')
        ->and($fresh->secret_changed_at->greaterThan($this->credential->secret_changed_at))->toBeTrue()
        ->and(AuditEntry::query()->orderBy('sequence')->pluck('event')->all())->toBe(['credential_updated', 'credential_secret_changed'])
        ->and(json_encode(AuditEntry::query()->get()))->not->toContain('replacement-secret-marker');

    expectNoSecrets($component, ['original-secret-marker', 'replacement-secret-marker'], $fresh);
    $component->assertSet('secret', '');
    expect(session('flash'))->toBe('Credential saved.');
});

it('never keeps a typed secret in the component when the form is rejected', function () {
    $component = editCredential($this->credential)
        ->set('secret', 'typed-secret-marker')
        ->set('host', 'repo.magento.com:443')
        ->call('save')
        ->assertHasErrors(['host'])
        ->assertSet('secret', '');

    expectNoSecrets($component, ['typed-secret-marker', 'original-secret-marker'], $this->credential);
    expect($this->credential->fresh()->revealSecret())->toBe('original-secret-marker');
});

it('requires a username for http-basic only', function () {
    editCredential($this->credential)->set('username', '')->call('save')->assertHasErrors(['username']);

    editCredential($this->credential)->set('type', 'bearer')->set('username', '')->call('save')->assertHasNoErrors();

    expect($this->credential->fresh()->type)->toBe(CredentialType::Bearer)
        ->and($this->credential->fresh()->username)->toBeNull();
});

it('rejects a host change that conflicts with another credential of a linked project', function () {
    $project = Project::factory()->create();
    $other = Credential::factory()->forHost('github.com')->create();
    $project->credentials()->attach([$this->credential->id, $other->id]);

    editCredential($this->credential)->set('host', 'github.com')->call('save')->assertHasErrors(['host']);

    expect($this->credential->fresh()->host)->toBe('repo.magento.com')
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('links and unlinks projects together with the other changes', function () {
    [$first, $second] = Project::factory()->count(2)->create();
    $this->credential->projects()->attach($first);

    $component = editCredential($this->credential)
        ->assertSet('projectIds', [$first->id])
        ->set('projectIds', [$second->id])
        ->set('name', 'Renamed')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->credential->projects()->pluck('projects.id')->all())->toBe([$second->id])
        ->and($this->credential->fresh()->name)->toBe('Renamed')
        ->and(AuditEntry::query()->orderBy('sequence')->pluck('event')->all())->toBe(['credential_updated', 'credential_links_changed']);
});

it('saves nothing when the projects conflict', function () {
    $project = Project::factory()->create();
    $project->credentials()->attach(Credential::factory()->forHost('repo.magento.com')->create());

    editCredential($this->credential)
        ->set('projectIds', [$project->id])
        ->set('name', 'Should not be saved')
        ->call('save')
        ->assertHasErrors(['projectIds']);

    expect($this->credential->fresh()->name)->toBe('Magento')
        ->and($this->credential->projects()->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('lists the projects that lose the credential in the delete modal', function () {
    $project = Project::factory()->create(['name' => 'Webshop EU']);
    $this->credential->projects()->attach($project);

    editCredential($this->credential)
        ->assertSee('Webshop EU')
        ->assertSee('lose this credential');
});

it('deletes the credential through the action and audits it', function () {
    $project = Project::factory()->create();
    $this->credential->projects()->attach($project);

    editCredential($this->credential)
        ->call('delete')
        ->assertRedirect(route('credentials.index'));

    expect(Credential::query()->count())->toBe(0)
        ->and(Project::query()->count())->toBe(1)
        ->and(session('flash'))->toBe('Credential deleted.');

    $entry = AuditEntry::query()->where('event', 'credential_deleted')->sole();

    expect($entry->subject_id)->toBe($this->credential->id)
        ->and($entry->properties->toArray())->toEqual(['name' => 'Magento', 'host' => 'repo.magento.com', 'subject_label' => 'Magento']);
});

it('cannot be pointed at another credential through the locked id', function () {
    $other = Credential::factory()->create();

    expect(fn () => editCredential($this->credential)->set('credentialId', $other->id))->toThrow(Exception::class);
});

it('gives a 404 for a credential that does not exist', function () {
    $this->get(route('credentials.edit', '01HZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
});

it('renders the edit page without ever decrypting the secret', function () {
    config(['vault.key' => vaultTestKey(9)]);

    $this->get(route('credentials.edit', $this->credential))->assertOk()->assertSeeLivewire('pages::credentials.edit');

    expect(fn () => $this->credential->fresh()->revealSecret())->toThrow(DecryptionFailedException::class);
});
