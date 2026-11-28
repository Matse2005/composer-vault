<?php

use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->withTwoFactor()->create());
});

it('gives every filter on the log pages an accessible name', function (string $page, array $labels) {
    $html = Livewire::test($page)->html();

    foreach ($labels as $label) {
        expect($html)->toContain('aria-label="'.$label.'"');
    }
})->with([
    'access log' => ['pages::logs.access', ['Filter on result', 'Filter on token', 'Filter on project']],
    'audit log' => ['pages::logs.audit', ['Filter on action', 'Filter on admin', 'Filter on subject']],
    'projects' => ['pages::projects.index', ['Search projects']],
]);

it('labels the global search', function () {
    expect(Livewire::test('global-search')->html())->toContain('aria-label="Search projects, credentials and tokens"');
});

it('shows a status with text and not with colour alone', function () {
    foreach (['active', 'revoked', 'expired', 'warning', 'failed'] as $status) {
        $html = Blade::render('<x-vault.status-badge status="'.$status.'" />');

        expect(trim(strip_tags($html)))->not->toBe('');
    }
});
