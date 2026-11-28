<?php

use App\Models\AccessToken;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use App\Vault\Authorization\Ability;
use App\Vault\Navigation\RecentProjects;
use App\Vault\Search\GlobalSearch;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->withTwoFactor()->create();
    $this->actingAs($this->admin);
});

it('records a visited project for the admin and lists the newest first', function () {
    $first = Project::factory()->create(['name' => 'First']);
    $second = Project::factory()->create(['name' => 'Second']);

    $this->get(route('projects.show', $first))->assertOk();
    $this->travel(1)->minutes();
    $this->get(route('projects.show', $second))->assertOk();

    expect(app(RecentProjects::class)->forUser($this->admin)->pluck('name')->all())->toBe(['Second', 'First']);

    $this->travel(1)->minutes();
    $this->get(route('projects.show', $first))->assertOk();

    expect(app(RecentProjects::class)->forUser($this->admin)->pluck('name')->all())->toBe(['First', 'Second'])
        ->and(DB::table('user_recent_projects')->count())->toBe(2);
});

it('keeps the recent projects per admin', function () {
    $project = Project::factory()->create();
    $other = User::factory()->withTwoFactor()->create();

    $this->get(route('projects.show', $project))->assertOk();

    expect(app(RecentProjects::class)->forUser($other))->toHaveCount(0);
});

it('lists at most eight recent projects', function () {
    foreach (Project::factory()->count(10)->create() as $index => $project) {
        $this->travelTo(now()->addMinutes($index));
        app(RecentProjects::class)->record($this->admin, $project);
    }

    expect(app(RecentProjects::class)->forUser($this->admin))->toHaveCount(8);
});

it('shows the recent projects and the search in the sidebar', function () {
    $project = Project::factory()->create(['name' => 'Webshop']);
    $this->get(route('projects.show', $project))->assertOk();

    $this->get(route('dashboard'))->assertOk()->assertSee('data-test="recent-project"', false)->assertSee('Webshop')->assertSee('data-test="global-search"', false);
});

it('finds projects, credentials and tokens and groups them by project', function () {
    $shop = Project::factory()->create(['name' => 'Webshop', 'slug' => 'webshop']);
    $blog = Project::factory()->create(['name' => 'Blog', 'slug' => 'blog']);
    Credential::factory()->for($shop)->forHost('repo.magento.com')->create(['name' => 'Magento keys']);
    Credential::factory()->for($blog)->forHost('github.com')->create(['name' => 'GitHub']);
    AccessToken::factory()->for($shop)->create(['name' => 'prod-web-01']);

    $groups = app(GlobalSearch::class)->search($this->admin, 'web');

    expect($groups)->toHaveCount(1)
        ->and($groups[0]['project']['slug'])->toBe('webshop')
        ->and(collect($groups[0]['matches'])->pluck('type')->all())->toBe(['project', 'token']);

    $byHost = app(GlobalSearch::class)->search($this->admin, 'magento');

    expect($byHost)->toHaveCount(1)->and($byHost[0]['matches'][0]['type'])->toBe('credential');
});

it('matches regardless of case', function () {
    Project::factory()->create(['name' => 'Webshop', 'slug' => 'webshop-eu']);

    expect(app(GlobalSearch::class)->search($this->admin, 'WEBSHOP'))->toHaveCount(1);
});

it('does not search below two characters or match wildcards', function () {
    Project::factory()->create(['name' => 'Webshop']);

    expect(app(GlobalSearch::class)->search($this->admin, 'w'))->toBe([])
        ->and(app(GlobalSearch::class)->search($this->admin, '%%'))->toBe([]);
});

it('only searches the kinds of record the admin may view', function () {
    $project = Project::factory()->create(['name' => 'Webshop']);
    Credential::factory()->for($project)->create(['name' => 'Webshop key']);
    AccessToken::factory()->for($project)->create(['name' => 'Webshop token']);

    $onlyProjects = userWithPermissions([Ability::ProjectsView]);
    $nothing = userWithPermissions([Ability::LogsView]);

    $types = collect(app(GlobalSearch::class)->search($onlyProjects, 'webshop')[0]['matches'])->pluck('type')->all();

    expect($types)->toBe(['project'])
        ->and(app(GlobalSearch::class)->search($nothing, 'webshop'))->toBe([]);
});

it('never reads a secret, a ciphertext or a token hash', function () {
    $project = Project::factory()->create(['name' => 'Webshop']);
    Credential::factory()->for($project)->create(['name' => 'Webshop key']);
    AccessToken::factory()->for($project)->create(['name' => 'Webshop token']);

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    app(GlobalSearch::class)->search($this->admin, 'webshop');

    $sql = implode(' ', $queries);

    expect($sql)->not->toContain('secret_ciphertext')->not->toContain('data_key_ciphertext')->not->toContain('token_hash')->not->toContain('select *');
});

it('shows the results in the sidebar component and nothing for a short term', function () {
    Project::factory()->create(['name' => 'Webshop', 'slug' => 'webshop']);

    Livewire::test('global-search')
        ->set('term', 'w')
        ->assertDontSee('data-test="search-results"', false)
        ->set('term', 'webs')
        ->assertSee('Webshop')
        ->set('term', 'zzzz')
        ->assertSee('Nothing found.');
});
