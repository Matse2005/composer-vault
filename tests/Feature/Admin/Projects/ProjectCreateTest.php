<?php

use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->actingAs($this->admin);
});

it('creates a project through the action and audits it', function () {
    Livewire::test('pages::projects.create')
        ->set('name', 'Krijmerieke Webshop')
        ->set('slug', 'krijmerieke')
        ->set('notes', 'Production shop')
        ->call('save')
        ->assertHasNoErrors();

    $project = Project::query()->sole();

    expect($project->name)->toBe('Krijmerieke Webshop')
        ->and($project->slug)->toBe('krijmerieke')
        ->and($project->notes)->toBe('Production shop');

    $entry = AuditEntry::query()->where('event', 'project_created')->sole();

    expect($entry->subject_id)->toBe($project->id)
        ->and((string) $entry->causer_id)->toBe((string) $this->admin->id);
});

it('redirects to the project with a flash message', function () {
    $component = Livewire::test('pages::projects.create')
        ->set('name', 'Shop')
        ->set('slug', 'shop')
        ->call('save');

    $project = Project::query()->sole();

    $component->assertRedirect(route('projects.show', $project));
    expect(session('flash'))->toBe('Project created.');
});

it('fills the slug from the name until the slug is edited', function () {
    Livewire::test('pages::projects.create')
        ->set('name', 'Krijmerieke Web Shop')
        ->assertSet('slug', 'krijmerieke-web-shop')
        ->set('name', 'Krijmerieke 2')
        ->assertSet('slug', 'krijmerieke-2')
        ->set('slug', 'custom-slug')
        ->set('name', 'Something else entirely')
        ->assertSet('slug', 'custom-slug');
});

it('rejects invalid input and creates nothing', function (string $name, string $slug, string $field) {
    Livewire::test('pages::projects.create')
        ->set('name', $name)
        ->set('slug', $slug)
        ->call('save')
        ->assertHasErrors([$field]);

    expect(Project::query()->count())->toBe(0)
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'no name' => ['', 'valid-slug', 'name'],
    'name too long' => [str_repeat('a', 101), 'valid-slug', 'name'],
    'no slug' => ['Shop', '', 'slug'],
    'uppercase slug' => ['Shop', 'Shop', 'slug'],
    'slug starting with a digit' => ['Shop', '1shop', 'slug'],
    'slug with an underscore' => ['Shop', 'my_shop', 'slug'],
    'one character slug' => ['Shop', 'a', 'slug'],
    'double hyphen' => ['Shop', 'my--shop', 'slug'],
    'trailing hyphen' => ['Shop', 'shop-', 'slug'],
    'slug too long' => ['Shop', 'a'.str_repeat('b', 64), 'slug'],
]);

it('rejects a slug that is already taken', function () {
    Project::factory()->create(['slug' => 'shop']);

    Livewire::test('pages::projects.create')
        ->set('name', 'Another shop')
        ->set('slug', 'shop')
        ->call('save')
        ->assertHasErrors(['slug']);

    expect(Project::query()->count())->toBe(1);
});

it('keeps the notes optional', function () {
    Livewire::test('pages::projects.create')
        ->set('name', 'Shop')
        ->set('slug', 'shop')
        ->call('save')
        ->assertHasNoErrors();

    expect(Project::query()->sole()->notes)->toBeNull();
});

it('renders the create page', function () {
    $this->get(route('projects.create'))->assertOk()->assertSeeLivewire('pages::projects.create');
});
