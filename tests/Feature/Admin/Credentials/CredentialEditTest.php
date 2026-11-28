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
    $this->project = Project::factory()->create(['name' => 'Webshop EU']);
    $this->credential = Credential::factory()
        ->for($this->project)
        ->ofType(CredentialType::HttpBasic)
        ->forHost('repo.magento.com')
        ->withSecret('original-secret-marker')
        ->create(['name' => 'Magento', 'username' => 'public-key', 'notes' => 'Production']);
});

function editCredential(Credential $credential): Testable
{
    return Livewire::test('pages::credentials.edit', ['project' => $credential->project_id, 'credential' => $credential]);
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

it('rejects a host change that conflicts with another credential of the same project', function () {
    Credential::factory()->for($this->project)->forHost('github.com')->create();

    editCredential($this->credential)->set('host', 'github.com')->call('save')->assertHasErrors(['host']);

    expect($this->credential->fresh()->host)->toBe('repo.magento.com')
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('accepts a host change to a host that only another project holds', function () {
    Credential::factory()->forHost('github.com')->create();

    editCredential($this->credential)->set('host', 'github.com')->call('save')->assertHasNoErrors();

    expect($this->credential->fresh()->host)->toBe('github.com');
});

it('moves the credential to another project and audits it', function () {
    $target = Project::factory()->create();

    editCredential($this->credential)
        ->set('targetProjectId', $target->id)
        ->call('move')
        ->assertHasNoErrors()
        ->assertRedirect(route('projects.show', $target));

    expect($this->credential->fresh()->project_id)->toBe($target->id)
        ->and($this->credential->fresh()->revealSecret())->toBe('original-secret-marker')
        ->and(session('flash'))->toBe('Credential moved.');

    $entry = AuditEntry::query()->where('event', 'credential_moved')->sole();

    expect($entry->subject_id)->toBe($this->credential->id)
        ->and($entry->properties->toArray())->toMatchArray(['from_project_id' => $this->project->id, 'to_project_id' => $target->id]);
});

it('refuses to move to a project that holds the host already and leaves the credential unchanged', function () {
    $target = Project::factory()->create();
    Credential::factory()->for($target)->forHost('repo.magento.com')->create();

    editCredential($this->credential)
        ->set('targetProjectId', $target->id)
        ->call('move')
        ->assertHasErrors(['targetProjectId'])
        ->assertNoRedirect();

    expect($this->credential->fresh()->project_id)->toBe($this->project->id)
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('does nothing when the target is the current project', function () {
    editCredential($this->credential)->set('targetProjectId', $this->project->id)->call('move')->assertHasNoErrors();

    expect($this->credential->fresh()->project_id)->toBe($this->project->id)
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('rejects a target project that does not exist', function () {
    editCredential($this->credential)->set('targetProjectId', '01HZZZZZZZZZZZZZZZZZZZZZZZ')->call('move')->assertHasErrors(['targetProjectId']);

    expect($this->credential->fresh()->project_id)->toBe($this->project->id);
});

it('deletes the credential through the action and audits it', function () {
    editCredential($this->credential)
        ->call('delete')
        ->assertRedirect(route('projects.show', $this->project));

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
    $this->get(route('credentials.edit', [$this->project, '01HZZZZZZZZZZZZZZZZZZZZZZZ']))->assertNotFound();
});

it('renders the edit page without ever decrypting the secret', function () {
    config(['vault.key' => vaultTestKey(9)]);

    $this->get(route('credentials.edit', [$this->project, $this->credential]))->assertOk()->assertSeeLivewire('pages::credentials.edit');

    expect(fn () => $this->credential->fresh()->revealSecret())->toThrow(DecryptionFailedException::class);
});
