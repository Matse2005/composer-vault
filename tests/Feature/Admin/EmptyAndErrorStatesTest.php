<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());
});

it('shows an empty state with the primary action on every index page', function (string $page, string $message, string $createRoute) {
    Livewire::test($page)->assertSee($message)->assertSee(route($createRoute), false);
})->with([
    'projects' => ['pages::projects.index', 'No projects yet', 'projects.create'],
]);

it('shows an empty state on the log pages', function (string $page, string $message) {
    Livewire::test($page)->assertSee($message);
})->with([
    'access log' => ['pages::logs.access', 'No requests match'],
    'audit log' => ['pages::logs.audit', 'No audit entries'],
]);

it('gives a 404 when the model of an open page was deleted in the meantime', function () {
    $project = Project::factory()->create();
    $component = Livewire::test('pages::projects.show', ['project' => $project]);

    $project->delete();

    $component->call('saveDetails')->assertNotFound();
});

it('shows the normal 404 page for an unknown admin url', function () {
    $this->get('/projects/does-not-exist/nothing')->assertNotFound();
});

it('does not send the message of an exception to the browser when debug is off', function () {
    config(['app.debug' => false]);
    Route::middleware(['web', 'auth', 'second-factor'])->get('/_test/boom', function () {
        throw new RuntimeException('internal secret detail');
    });

    $response = $this->get('/_test/boom');

    $response->assertStatus(500);
    expect($response->getContent())->not->toContain('internal secret detail');
});
