<?php

use App\Models\AccessToken;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());
});

it('lists tokens with prefix, projects, status, expiry and last use', function () {
    $project = Project::factory()->create(['slug' => 'webshop']);
    $token = AccessToken::factory()->create([
        'name' => 'prod-web-01',
        'token_prefix' => 'AbCdEfGh',
        'expires_at' => now()->addDays(10),
        'last_used_at' => now()->subHours(2),
        'last_used_ip' => '203.0.113.7',
    ]);
    $token->projects()->attach($project);

    Livewire::test('pages::tokens.index')
        ->assertSee('prod-web-01')
        ->assertSee('cv_AbCdEfGh...')
        ->assertSee('webshop')
        ->assertSee('Active')
        ->assertSee('203.0.113.7')
        ->assertSee('2 hours ago')
        ->assertSee(now()->addDays(10)->toDateString());
});

it('shows never used and no expiry for a fresh token', function () {
    AccessToken::factory()->create();

    Livewire::test('pages::tokens.index')->assertSee('Never used')->assertSee('No expiry');
});

it('shows the status of every kind of token', function () {
    AccessToken::factory()->create(['name' => 'active-one']);
    AccessToken::factory()->expired()->create(['name' => 'expired-one']);
    AccessToken::factory()->revoked()->create(['name' => 'revoked-one']);
    AccessToken::factory()->revoked()->expired()->create(['name' => 'both-one']);

    Livewire::test('pages::tokens.index')
        ->assertSeeInOrder(['active-one', 'Active'])
        ->assertSeeInOrder(['expired-one', 'Expired'])
        ->assertSeeInOrder(['revoked-one', 'Revoked'])
        ->assertSeeInOrder(['both-one', 'Revoked']);
});

it('filters by status', function () {
    AccessToken::factory()->create(['name' => 'active-one']);
    AccessToken::factory()->expired()->create(['name' => 'expired-one']);
    AccessToken::factory()->revoked()->create(['name' => 'revoked-one']);
    AccessToken::factory()->revoked()->expired()->create(['name' => 'both-one']);

    Livewire::test('pages::tokens.index')
        ->set('status', 'active')
        ->assertSee('active-one')->assertDontSee('expired-one')->assertDontSee('revoked-one')->assertDontSee('both-one')
        ->set('status', 'expired')
        ->assertSee('expired-one')->assertDontSee('active-one')->assertDontSee('revoked-one')->assertDontSee('both-one')
        ->set('status', 'revoked')
        ->assertSee('revoked-one')->assertSee('both-one')->assertDontSee('active-one')->assertDontSee('expired-one')
        ->set('status', '')
        ->assertSee('active-one')->assertSee('expired-one')->assertSee('revoked-one');
});

it('ignores an unknown status filter', function () {
    AccessToken::factory()->create(['name' => 'visible-one']);

    Livewire::test('pages::tokens.index')->set('status', 'nonsense')->assertSee('visible-one');
});

it('shows 25 tokens a page', function () {
    foreach (range(1, 30) as $number) {
        AccessToken::factory()->create(['name' => sprintf('token-%02d', $number)]);
    }

    Livewire::test('pages::tokens.index')
        ->assertSee('token-25')
        ->assertDontSee('token-26')
        ->call('gotoPage', 2)
        ->assertSee('token-30');
});

it('shows an empty state with the primary action', function () {
    Livewire::test('pages::tokens.index')
        ->assertSee('No tokens yet')
        ->assertSee(route('tokens.create'), false);
});

it('never renders a token hash', function () {
    $token = AccessToken::factory()->create();

    $component = Livewire::test('pages::tokens.index');

    foreach ([$component->html(), json_encode($component->snapshot)] as $output) {
        expect($output)->not->toContain($token->token_hash);
    }
});

it('links every token to its page', function () {
    $token = AccessToken::factory()->create();

    $this->get(route('tokens.index'))->assertOk()->assertSee(route('tokens.show', $token), false);
});
