<?php

use App\Enums\CredentialType;
use App\Models\AccessToken;
use App\Models\AuditEntry;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->actingAs($this->admin);
    $this->project = Project::factory()->create(['name' => 'Webshop', 'slug' => 'webshop', 'notes' => 'Original notes']);
});

function showProject(Project $project): Testable
{
    return Livewire::test('pages::projects.show', ['project' => $project]);
}

it('shows the details of the project', function () {
    showProject($this->project)
        ->assertSet('name', 'Webshop')
        ->assertSet('slug', 'webshop')
        ->assertSet('notes', 'Original notes');
});

it('updates the details through the action and audits the change', function () {
    showProject($this->project)
        ->set('name', 'Webshop EU')
        ->set('notes', 'New notes')
        ->call('saveDetails')
        ->assertHasNoErrors();

    expect($this->project->fresh()->name)->toBe('Webshop EU')
        ->and($this->project->fresh()->notes)->toBe('New notes')
        ->and(session('flash'))->toBe('Project saved.');

    $entry = AuditEntry::query()->where('event', 'project_updated')->sole();

    expect($entry->subject_id)->toBe($this->project->id)
        ->and($entry->properties->toArray())->toEqual(['name' => ['old' => 'Webshop', 'new' => 'Webshop EU'], 'notes' => 'changed']);
});

it('warns about every composer.json when the slug is changed', function () {
    showProject($this->project)
        ->assertDontSee('composer.json')
        ->set('slug', 'webshop-eu')
        ->assertSee('composer.json')
        ->assertSee('webshop');
});

it('saves a changed slug', function () {
    showProject($this->project)->set('slug', 'webshop-eu')->call('saveDetails')->assertHasNoErrors();

    expect($this->project->fresh()->slug)->toBe('webshop-eu');
});

it('does not treat its own slug as a duplicate', function () {
    showProject($this->project)->set('name', 'Renamed')->call('saveDetails')->assertHasNoErrors();
});

it('rejects the slug of another project, an invalid slug and an empty name', function (string $field, string $value) {
    Project::factory()->create(['slug' => 'taken']);

    showProject($this->project)->set($field, $value)->call('saveDetails')->assertHasErrors([$field]);

    expect($this->project->fresh()->only(['name', 'slug']))->toBe(['name' => 'Webshop', 'slug' => 'webshop'])
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'taken slug' => ['slug', 'taken'],
    'invalid slug' => ['slug', 'Not Valid'],
    'empty name' => ['name', ''],
]);

it('lists all credentials as a checkbox list and marks the linked ones', function () {
    $linked = Credential::factory()->ofType(CredentialType::HttpBasic)->forHost('repo.magento.com')->create(['name' => 'Magento']);
    Credential::factory()->ofType(CredentialType::GithubOauth)->forHost('github.com')->create(['name' => 'GitHub']);
    $this->project->credentials()->attach($linked);

    showProject($this->project)
        ->assertSet('credentialIds', [$linked->id])
        ->assertSee('Magento (HTTP basic, repo.magento.com)')
        ->assertSee('GitHub (GitHub OAuth token, github.com)');
});

it('links and unlinks credentials through the action and audits it', function () {
    $first = Credential::factory()->forHost('repo.magento.com')->create();
    $second = Credential::factory()->forHost('github.com')->create();
    $this->project->credentials()->attach($first);

    showProject($this->project)
        ->set('credentialIds', [$second->id])
        ->call('saveCredentials')
        ->assertHasNoErrors();

    expect($this->project->credentials()->pluck('credentials.id')->all())->toBe([$second->id]);

    $entry = AuditEntry::query()->where('event', 'credential_links_changed')->sole();

    expect($entry->subject_id)->toBe($this->project->id)
        ->and($entry->properties->toArray())->toEqual(['added' => [$second->id], 'removed' => [$first->id]]);
    expect(session('flash'))->toBe('Credentials saved.');
});

it('shows a host conflict as a validation error and saves nothing', function () {
    $keep = Credential::factory()->forHost('github.com')->create();
    $first = Credential::factory()->forHost('repo.magento.com')->create(['name' => 'Magento one']);
    $second = Credential::factory()->forHost('repo.magento.com')->create(['name' => 'Magento two']);
    $this->project->credentials()->attach($keep);

    showProject($this->project)
        ->set('credentialIds', [$first->id, $second->id])
        ->call('saveCredentials')
        ->assertHasErrors(['credentialIds']);

    expect($this->project->credentials()->pluck('credentials.id')->all())->toBe([$keep->id])
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('ignores credential ids that were not offered', function () {
    showProject($this->project)
        ->set('credentialIds', ['01HZZZZZZZZZZZZZZZZZZZZZZZ'])
        ->call('saveCredentials')
        ->assertHasErrors(['credentialIds.0']);

    expect($this->project->credentials()->count())->toBe(0);
});

it('lists the linked tokens read-only with status and a link', function () {
    $active = AccessToken::factory()->create(['name' => 'prod-web-01']);
    $revoked = AccessToken::factory()->revoked()->create(['name' => 'old-server']);
    AccessToken::factory()->create(['name' => 'unlinked-token']);
    $this->project->accessTokens()->attach([$active->id, $revoked->id]);

    showProject($this->project)
        ->assertSee('prod-web-01')
        ->assertSee('old-server')
        ->assertSee('Active')
        ->assertSee('Revoked')
        ->assertSee(route('tokens.show', $active), false)
        ->assertDontSee('unlinked-token');
});

it('refuses to delete with a wrong confirmation', function () {
    showProject($this->project)
        ->set('deleteConfirmation', 'something-else')
        ->call('delete')
        ->assertHasErrors(['deleteConfirmation']);

    expect(Project::query()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('deletes with the slug typed, keeps the credentials and removes the token links', function () {
    $credential = Credential::factory()->create();
    $token = AccessToken::factory()->create();
    $this->project->credentials()->attach($credential);
    $this->project->accessTokens()->attach($token);

    showProject($this->project)
        ->set('deleteConfirmation', 'webshop')
        ->call('delete')
        ->assertHasNoErrors()
        ->assertRedirect(route('projects.index'));

    expect(Project::query()->count())->toBe(0)
        ->and($credential->fresh())->not->toBeNull()
        ->and($token->fresh())->not->toBeNull()
        ->and($token->projects()->count())->toBe(0)
        ->and(session('flash'))->toBe('Project deleted.');

    $entry = AuditEntry::query()->where('event', 'project_deleted')->sole();

    expect($entry->subject_id)->toBe($this->project->id)
        ->and($entry->properties->toArray())->toEqual(['name' => 'Webshop', 'slug' => 'webshop', 'subject_label' => 'Webshop']);
});

it('explains the consequences in the delete modal', function () {
    showProject($this->project)
        ->assertSee('lose access to this project')
        ->assertSee('credentials stay');
});

it('cannot be pointed at another project through the locked id', function () {
    $other = Project::factory()->create(['slug' => 'other']);

    expect(fn () => showProject($this->project)->set('projectId', $other->id))->toThrow(Exception::class);
});

it('gives a 404 for a project that does not exist', function () {
    $this->get(route('projects.show', '01HZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
});

it('renders the show page', function () {
    $this->get(route('projects.show', $this->project))->assertOk()->assertSeeLivewire('pages::projects.show');
});
