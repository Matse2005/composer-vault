<?php

use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());
});

it('lists credentials with type, host, username, project count and the date the secret changed', function () {
    $credential = Credential::factory()->ofType(CredentialType::HttpBasic)->forHost('repo.magento.com')
        ->create(['name' => 'Magento', 'username' => 'public-key']);
    $credential->forceFill(['secret_changed_at' => now()->subDays(3)])->save();
    $credential->projects()->attach(Project::factory()->count(2)->create());

    Livewire::test('pages::credentials.index')
        ->assertSee('Magento')
        ->assertSee('HTTP basic')
        ->assertSee('repo.magento.com')
        ->assertSee('public-key')
        ->assertSeeInOrder(['public-key', '2'])
        ->assertSee('3 days ago');
});

it('filters by type', function () {
    Credential::factory()->ofType(CredentialType::Bearer)->forHost('a.example.com')->create(['name' => 'Bearer one']);
    Credential::factory()->ofType(CredentialType::GithubOauth)->forHost('github.com')->create(['name' => 'GitHub one']);

    Livewire::test('pages::credentials.index')
        ->set('type', 'github-oauth')
        ->assertSee('GitHub one')
        ->assertDontSee('Bearer one')
        ->set('type', '')
        ->assertSee('Bearer one')
        ->assertSee('GitHub one');
});

it('ignores a type filter that is not a credential type', function () {
    Credential::factory()->create(['name' => 'Visible']);

    Livewire::test('pages::credentials.index')->set('type', 'nonsense')->assertSee('Visible');
});

it('searches on name and host', function () {
    Credential::factory()->forHost('repo.magento.com')->create(['name' => 'Magento']);
    Credential::factory()->forHost('github.com')->create(['name' => 'Code hosting']);

    Livewire::test('pages::credentials.index')
        ->set('search', 'magento')
        ->assertSee('Magento')
        ->assertDontSee('Code hosting')
        ->set('search', 'github')
        ->assertSee('Code hosting')
        ->assertDontSee('Magento');
});

it('shows 25 credentials a page', function () {
    foreach (range(1, 30) as $number) {
        Credential::factory()->forHost(sprintf('host%02d.example.com', $number))->create(['name' => sprintf('Credential %02d', $number)]);
    }

    Livewire::test('pages::credentials.index')
        ->assertSee('Credential 25')
        ->assertDontSee('Credential 26')
        ->call('gotoPage', 2)
        ->assertSee('Credential 30');
});

it('shows an empty state with the primary action', function () {
    Livewire::test('pages::credentials.index')
        ->assertSee('No credentials yet')
        ->assertSee(route('credentials.create'), false);
});

it('never renders a secret or a ciphertext', function () {
    $credential = Credential::factory()->withSecret('plaintext-secret-marker')->create();

    $component = Livewire::test('pages::credentials.index');

    foreach ([$component->html(), json_encode($component->snapshot)] as $output) {
        expect($output)->not->toContain('plaintext-secret-marker')
            ->not->toContain($credential->secret_ciphertext)
            ->not->toContain($credential->data_key_ciphertext);
    }
});

it('links every credential to its edit page', function () {
    $credential = Credential::factory()->create();

    $this->get(route('credentials.index'))->assertOk()->assertSee(route('credentials.edit', $credential), false);
});
