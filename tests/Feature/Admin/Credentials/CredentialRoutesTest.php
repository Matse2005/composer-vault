<?php

use App\Models\AccessToken;
use App\Models\Credential;
use App\Models\Project;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());
    $this->project = Project::factory()->create();
    $this->otherProject = Project::factory()->create();
});

it('no longer has a global list of credentials or tokens', function (string $path) {
    $this->get($path)->assertNotFound();
})->with(['/credentials', '/tokens']);

it('gives a 404 for a credential or token under the URL of another project', function () {
    $credential = Credential::factory()->for($this->project)->create();
    $token = AccessToken::factory()->for($this->project)->create();

    $this->get(route('credentials.edit', [$this->otherProject, $credential]))->assertNotFound();
    $this->get(route('tokens.show', [$this->otherProject, $token]))->assertNotFound();
});

it('renders the credential and token pages under the right project', function () {
    $credential = Credential::factory()->for($this->project)->create();
    $token = AccessToken::factory()->for($this->project)->create();

    $this->get(route('credentials.create', $this->project))->assertOk()->assertSeeLivewire('pages::credentials.create');
    $this->get(route('credentials.edit', [$this->project, $credential]))->assertOk()->assertSeeLivewire('pages::credentials.edit');
    $this->get(route('tokens.create', $this->project))->assertOk()->assertSeeLivewire('pages::tokens.create');
    $this->get(route('tokens.show', [$this->project, $token]))->assertOk()->assertSeeLivewire('pages::tokens.show');
});

it('gives a 404 for the create pages of a project that does not exist', function (string $route) {
    $this->get(route($route, '01HZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
})->with(['credentials.create', 'tokens.create']);
