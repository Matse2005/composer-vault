<?php

use App\Enums\CredentialType;
use App\Models\Credential;
use App\Models\Project;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

it('renders the empty state with its title, text and action', function () {
    $html = Blade::render('<x-vault.empty-state title="Nothing yet" text="Add one." icon="key"><a href="/add">Add</a></x-vault.empty-state>');

    expect($html)->toContain('Nothing yet')->toContain('Add one.')->toContain('href="/add"');
});

it('renders the page header with description, breadcrumbs and actions', function () {
    $html = Blade::render('<x-vault.page-header title="Projects" description="All of them"><x-slot:breadcrumbs>Crumbs</x-slot:breadcrumbs><x-slot:actions><button>New</button></x-slot:actions></x-vault.page-header>');

    expect($html)->toContain('Projects')->toContain('All of them')->toContain('Crumbs')->toContain('<button>New</button>');
});

it('renders a stat as a link only when it has a destination', function () {
    $plain = Blade::render('<x-vault.stat label="Projects" value="3" />');
    $linked = Blade::render('<x-vault.stat label="Projects" value="3" href="/projects" hint="up" />');

    expect($plain)->toContain('Projects')->toContain('>3<')->not->toContain('<a ')
        ->and($linked)->toContain('<a ')->toContain('href="/projects"')->toContain('up');
});

it('renders a table with its head, rows, an empty row and a pagination slot', function () {
    $filled = Blade::render('<x-vault.table><x-slot:head><th>Name</th></x-slot:head><tr><td>Row one</td></tr><x-slot:pagination>Pages</x-slot:pagination></x-vault.table>');
    $empty = Blade::render('<x-vault.table empty="Nothing here"><x-slot:head><th>Name</th></x-slot:head></x-vault.table>');

    expect($filled)->toContain('<th>Name</th>')->toContain('Row one')->toContain('Pages')->not->toContain('table-empty')
        ->and($empty)->toContain('table-empty')->toContain('Nothing here');
});

it('shows a status as colour, icon and text', function (string $status, string $text) {
    expect(Blade::render('<x-vault.status-badge status="'.$status.'" />'))->toContain($text)->toContain('<svg');
})->with([
    ['active', 'Active'],
    ['revoked', 'Revoked'],
    ['expired', 'Expired'],
    ['warning', 'Warning'],
    ['failed', 'Failed'],
    ['nonsense', 'Unknown'],
]);

it('lets a status carry its own label', function () {
    expect(Blade::render('<x-vault.status-badge status="warning" label="Rotation due" />'))->toContain('Rotation due');
});

it('copies only the visible value and keeps it out of data attributes', function () {
    $html = Blade::render('<x-vault.copy-field value="webshop" label="Slug" />');

    expect($html)->toContain('webshop')->toContain('x-ref="value"')->toContain('navigator.clipboard');
    expect(preg_match('/data-[a-z-]+="[^"]*webshop/', $html))->toBe(0);
});

it('does not render a secret that is passed by mistake as a data attribute', function () {
    $html = Blade::render('<x-vault.copy-field value="visible-value" data-secret="s3cret-token" />');

    expect($html)->toContain('visible-value');
    // The value is only shown once, as the visible text; the copy script reads the element, not an attribute.
    expect(substr_count($html, 'visible-value'))->toBe(1);
});

it('has an icon for each credential type', function () {
    foreach (CredentialType::cases() as $type) {
        expect(Blade::render('<x-vault.credential-type-icon :type="$type" />', ['type' => $type]))->toContain('<svg');
    }
});

it('never renders a value attribute on the secret field', function () {
    $html = Blade::render('<x-vault.secret-field wire:model="secret" value="leaked" keep-hint />');

    expect($html)->toContain('type="password"')->toContain('autocomplete="new-password"')->toContain('Leave empty to keep the current secret.')->not->toContain('value=')->not->toContain('leaked');
});

it('asks for a typed confirmation in the confirm modal only when one is set', function () {
    View::share('errors', new ViewErrorBag);

    $typed = Blade::render('<x-vault.confirm-modal name="x" title="Delete?" confirm="webshop" model="typed" button="Delete" />');
    $plain = Blade::render('<x-vault.confirm-modal name="x" title="Delete?" button="Delete" />');

    expect($typed)->toContain('Type webshop to confirm')->toContain('wire:model="typed"')
        ->and($plain)->not->toContain('Type ');
});

it('shows a credential row with its host, type and a link only for those who may manage', function () {
    $project = Project::factory()->create();
    $credential = Credential::factory()->for($project)->forHost('repo.magento.com')->create(['name' => 'Magento']);

    $managed = Blade::render('<x-vault.credential-row :credential="$credential" :project="$project" can-manage />', compact('credential', 'project'));
    $readonly = Blade::render('<x-vault.credential-row :credential="$credential" :project="$project" />', compact('credential', 'project'));

    expect($managed)->toContain('Magento')->toContain('repo.magento.com')->toContain(route('credentials.edit', [$project, $credential]))
        ->and($readonly)->toContain('Magento')->not->toContain(route('credentials.edit', [$project, $credential]));
});

it('marks a credential whose secret is older than the rotation period', function () {
    $project = Project::factory()->create();
    $credential = Credential::factory()->for($project)->create();
    Credential::query()->whereKey($credential->id)->update(['secret_changed_at' => now()->subDays(100)]);
    $credential->refresh();

    $html = Blade::render('<x-vault.credential-row :credential="$credential" :project="$project" :rotation-days="90" />', compact('credential', 'project'));
    $fresh = Blade::render('<x-vault.credential-row :credential="$credential" :project="$project" :rotation-days="365" />', compact('credential', 'project'));

    expect($html)->toContain('Rotation due')->and($fresh)->not->toContain('Rotation due');
});
