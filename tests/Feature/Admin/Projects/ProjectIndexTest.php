<?php

use App\Models\AccessToken;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());
});

it('lists projects with their credential and token counts', function () {
    $project = Project::factory()->create(['name' => 'Webshop', 'slug' => 'webshop']);
    Credential::factory()->count(2)->for($project)->sequence(['host' => 'a.example.com'], ['host' => 'b.example.com'])->create();
    AccessToken::factory()->count(3)->for($project)->create();
    Project::factory()->create(['name' => 'Blog', 'slug' => 'blog']);

    Livewire::test('pages::projects.index')
        ->assertSee('Webshop')
        ->assertSee('webshop')
        ->assertSee('Blog')
        ->assertSeeInOrder(['Webshop', '2', '3', 'Never']);
});

it('searches on name and slug', function () {
    Project::factory()->create(['name' => 'Webshop', 'slug' => 'shop-eu']);
    Project::factory()->create(['name' => 'Blog', 'slug' => 'blog']);
    Project::factory()->create(['name' => 'Intranet', 'slug' => 'webportal']);

    Livewire::test('pages::projects.index')
        ->set('search', 'shop')
        ->assertSee('Webshop')
        ->assertDontSee('Blog')
        ->assertDontSee('Intranet')
        ->set('search', 'webportal')
        ->assertSee('Intranet')
        ->assertDontSee('Webshop');
});

it('treats search wildcards as plain text', function () {
    Project::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
    Project::factory()->create(['name' => '100% sure', 'slug' => 'sure']);

    Livewire::test('pages::projects.index')
        ->set('search', '%')
        ->assertSee('100% sure')
        ->assertDontSee('Alpha');
});

it('shows 25 projects a page', function () {
    foreach (range(1, 30) as $number) {
        Project::factory()->create(['name' => sprintf('Project %02d', $number), 'slug' => sprintf('project-%02d', $number)]);
    }

    Livewire::test('pages::projects.index')
        ->assertSee('Project 25')
        ->assertDontSee('Project 26')
        ->call('gotoPage', 2)
        ->assertSee('Project 26')
        ->assertSee('Project 30')
        ->assertDontSee('Project 01');
});

it('sorts by a column in both directions and keeps the sort in the url', function () {
    Project::factory()->create(['name' => 'Beta', 'slug' => 'aaa']);
    Project::factory()->create(['name' => 'Alpha', 'slug' => 'zzz']);

    Livewire::test('pages::projects.index')
        ->assertSeeInOrder(['Alpha', 'Beta'])
        ->call('sort', 'slug')
        ->assertSet('sortBy', 'slug')
        ->assertSet('sortDirection', 'asc')
        ->assertSeeInOrder(['Beta', 'Alpha'])
        ->call('sort', 'slug')
        ->assertSet('sortDirection', 'desc')
        ->assertSeeInOrder(['Alpha', 'Beta']);
});

it('ignores a sort column that is not allowed', function () {
    Project::factory()->create();

    Livewire::withQueryParams(['sortBy' => 'notes; drop table projects'])
        ->test('pages::projects.index')
        ->assertOk();

    expect(Project::query()->count())->toBe(1);
});

it('shows an empty state with the primary action', function () {
    Livewire::test('pages::projects.index')
        ->assertSee('No projects yet')
        ->assertSee(route('projects.create'), false);
});

it('shows a different message when a search finds nothing', function () {
    Project::factory()->create(['name' => 'Webshop']);

    Livewire::test('pages::projects.index')
        ->set('search', 'nothing like this')
        ->assertSee('No projects match')
        ->assertDontSee('No projects yet');
});

it('links to the new project page and to every project', function () {
    $project = Project::factory()->create(['name' => 'Webshop']);

    $this->get(route('projects.index'))
        ->assertOk()
        ->assertSee(route('projects.create'), false)
        ->assertSee(route('projects.show', $project), false);
});

it('keeps the page behind the second factor', function () {
    auth()->logout();
    $this->actingAs(User::factory()->create());

    $this->get(route('projects.index'))->assertRedirect(route('security.edit'));
});
