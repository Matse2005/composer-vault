<?php

use App\Enums\AccessResult;
use App\Models\AccessLog;
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
        ->assertDontSee('Changing the slug breaks existing setups')
        ->set('slug', 'webshop-eu')
        ->assertSee('Changing the slug breaks existing setups');
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

it('lists only the credentials of this project, with links to edit them', function () {
    $mine = Credential::factory()->for($this->project)->forHost('repo.magento.com')->create(['name' => 'Magento']);
    Credential::factory()->forHost('repo.magento.com')->create(['name' => 'Foreign credential']);

    showProject($this->project)
        ->assertSee('Magento')
        ->assertSee('repo.magento.com')
        ->assertSee(route('credentials.edit', [$this->project, $mine]), false)
        ->assertDontSee('Foreign credential');
});

it('lists only the tokens of this project with status and a link', function () {
    $active = AccessToken::factory()->for($this->project)->create(['name' => 'prod-web-01']);
    AccessToken::factory()->for($this->project)->revoked()->create(['name' => 'old-server']);
    AccessToken::factory()->create(['name' => 'foreign-token']);

    showProject($this->project)
        ->assertSee('prod-web-01')
        ->assertSee('old-server')
        ->assertSee('Active')
        ->assertSee('Revoked')
        ->assertSee(route('tokens.show', [$this->project, $active]), false)
        ->assertDontSee('foreign-token');
});

it('shows an empty state with an add button when the project has no credentials', function () {
    $this->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertSee('No credentials yet')
        ->assertSee(route('credentials.create', $this->project), false);
});

it('shows the secret of no credential and the hash of no token', function () {
    $credential = Credential::factory()->for($this->project)->create();
    $token = AccessToken::factory()->for($this->project)->create();

    $html = $this->get(route('projects.show', $this->project))->assertOk()->getContent();

    expect($html)->not->toContain($token->token_hash)
        ->and($html)->not->toContain((string) $credential->getRawOriginal('secret_ciphertext'));
});

it('shows the last successful fetch and the recent activity of the project', function () {
    expect(showProject($this->project)->assertSee('never fetched'))->not->toBeNull();

    AccessLog::factory()->create(['project_id' => $this->project->id, 'result' => AccessResult::Ok, 'ip' => '203.0.113.7']);
    AccessLog::factory()->create(['project_id' => Project::factory()->create()->id, 'result' => AccessResult::Ok, 'ip' => '198.51.100.9']);

    showProject($this->project)
        ->assertDontSee('never fetched')
        ->assertSee('203.0.113.7')
        ->assertDontSee('198.51.100.9');
});

it('shows the composer snippet with the slug of the project', function () {
    showProject($this->project)->assertSee('/api/v1/projects/webshop/composer-auth', false);
});

it('refuses to delete with a wrong confirmation', function () {
    showProject($this->project)
        ->set('deleteConfirmation', 'something-else')
        ->call('delete')
        ->assertHasErrors(['deleteConfirmation']);

    expect(Project::query()->count())->toBe(1)
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('lists what goes with the project in the delete modal', function () {
    Credential::factory()->for($this->project)->create(['name' => 'Magento', 'host' => 'repo.magento.com']);
    AccessToken::factory()->for($this->project)->create(['name' => 'prod-web-01']);

    showProject($this->project)
        ->assertSee('Credential Magento')
        ->assertSee('Server token prod-web-01');
});

it('deletes with the slug typed and removes its credentials and tokens', function () {
    $credential = Credential::factory()->for($this->project)->create();
    $token = AccessToken::factory()->for($this->project)->create();
    $foreign = Credential::factory()->create();

    showProject($this->project)
        ->set('deleteConfirmation', 'webshop')
        ->call('delete')
        ->assertHasNoErrors()
        ->assertRedirect(route('projects.index'));

    expect(Project::query()->count())->toBe(1)
        ->and($credential->fresh())->toBeNull()
        ->and($token->fresh())->toBeNull()
        ->and($foreign->fresh())->not->toBeNull()
        ->and(session('flash'))->toBe('Project deleted.');

    $entry = AuditEntry::query()->where('event', 'project_deleted')->sole();

    expect($entry->subject_id)->toBe($this->project->id)
        ->and($entry->properties->toArray())->toEqual(['name' => 'Webshop', 'slug' => 'webshop', 'subject_label' => 'Webshop']);
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
