<?php

use App\Models\User;
use App\Support\Csp\VaultPolicy;
use Illuminate\Foundation\Vite;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Csp\Policy;

beforeEach(function () {
    config(['app.debug' => false]);

    // Render the built assets, not the dev server of a local public/hot file. Laravel's Vite helper caches the
    // rendered dev server tags in a static property for the whole PHP process, nonce included, which would leak the
    // nonce of an earlier test into this one. A real request never shares a process with another one.
    app(Vite::class)->useHotFile(storage_path('framework/no-vite-hot-file'));

    // Livewire keeps the rendered @assets blocks in static properties too, with the nonce they were rendered with.
    Livewire::flushState();
});

/**
 * @return array<string, array<int, string>>
 */
function policyDirectives(TestResponse $response, string $header = 'Content-Security-Policy'): array
{
    $directives = [];

    foreach (explode(';', (string) $response->headers->get($header)) as $directive) {
        $parts = preg_split('/\s+/', trim($directive));
        $name = array_shift($parts);

        if ($name !== '' && $name !== null) {
            $directives[$name] = $parts;
        }
    }

    return $directives;
}

function headerNonce(TestResponse $response): string
{
    preg_match("/script-src[^;]*'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $matches);

    return $matches[1] ?? '';
}

it('sends exactly the directives of the plan', function () {
    $response = $this->get('/login')->assertOk();
    $directives = policyDirectives($response);
    $nonce = headerNonce($response);

    expect($nonce)->not->toBe('')
        ->and($directives['default-src'])->toBe(["'self'"])
        ->and($directives['script-src'])->toBe(["'self'", "'nonce-{$nonce}'"])
        ->and($directives['style-src'])->toBe(["'self'", "'unsafe-inline'"])
        ->and($directives['img-src'])->toBe(["'self'", 'data:'])
        ->and($directives['font-src'])->toBe(["'self'"])
        ->and($directives['connect-src'])->toBe(["'self'"])
        ->and($directives['form-action'])->toBe(["'self'"])
        ->and($directives['frame-ancestors'])->toBe(["'none'"])
        ->and($directives['base-uri'])->toBe(["'none'"])
        ->and($directives['object-src'])->toBe(["'none'"])
        ->and(array_keys($directives))->toHaveCount(10);
});

it('never allows eval or inline scripts', function () {
    $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');
    $scriptDirective = collect(explode(';', $policy))->first(fn (string $directive) => str_starts_with(trim($directive), 'script-src'));

    expect($policy)->not->toContain('unsafe-eval')
        ->and($scriptDirective)->not->toContain('unsafe-inline')
        ->and($scriptDirective)->not->toContain('*')
        ->and($policy)->not->toContain('http:')
        ->and($policy)->not->toContain('https:');
});

it('uses a different nonce for every request', function () {
    $first = headerNonce($this->get('/login'));
    $second = headerNonce($this->get('/login'));

    expect($first)->not->toBe('')
        ->and($second)->not->toBe('')
        ->and($first)->not->toBe($second);
});

it('uses the nonce of the header in the Vite, Livewire and Flux script tags', function (string $page, bool $signedIn) {
    if ($signedIn) {
        $this->actingAs(User::factory()->withTwoFactor()->create());
    }

    $response = $this->get($page)->assertOk();
    $nonce = headerNonce($response);
    $html = $response->getContent();

    expect($nonce)->not->toBe('');

    preg_match_all('/<script\b[^>]*>/i', $html, $scripts);

    expect($scripts[0])->not->toBeEmpty();

    foreach ($scripts[0] as $scriptTag) {
        expect($scriptTag)->toContain('nonce="'.$nonce.'"');
    }

    expect($html)->toMatch('/<script[^>]*src="[^"]*livewire[^"]*"[^>]*nonce="'.$nonce.'"/')
        ->toMatch('/<script[^>]*src="[^"]*flux[^"]*"[^>]*nonce="'.$nonce.'"/');
})->with(['login page' => ['/login', false], 'dashboard' => ['/dashboard', true]]);

it('puts the nonce on the Flux appearance style and script', function () {
    $response = $this->get('/login');
    $nonce = headerNonce($response);

    expect($response->getContent())->toContain('<style nonce="'.$nonce.'">')
        ->and($response->getContent())->toMatch('/<script nonce="'.$nonce.'">\s*window\.Flux/');
});

it('has no inline event handler or javascript url in the pages', function (string $page, bool $signedIn) {
    if ($signedIn) {
        $this->actingAs(User::factory()->withTwoFactor()->create());
        $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    $html = $this->get($page)->assertOk()->getContent();

    expect($html)->not->toMatch('/\son(click|load|error|submit|change|input)=/i')
        ->not->toContain('javascript:');
})->with([
    'login page' => ['/login', false],
    'dashboard' => ['/dashboard', true],
    'security page' => ['/settings/security', true],
]);

it('is enforcing by default and report-only when asked', function () {
    expect(config('csp.presets'))->toBe([VaultPolicy::class])
        ->and(config('csp.report_only_presets'))->toBe([]);

    config(['csp.presets' => [], 'csp.report_only_presets' => [VaultPolicy::class]]);

    $response = $this->get('/login')->assertOk();

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'")
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->not->toContain('unsafe-eval');
});

it('can be switched off', function () {
    config(['csp.enabled' => false]);

    expect($this->get('/login')->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('builds the same policy from the preset directly', function () {
    $policy = Policy::create([VaultPolicy::class])->getContents();

    expect($policy)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("base-uri 'none'")
        ->toContain("object-src 'none'")
        ->toContain("style-src 'self' 'unsafe-inline'")
        ->toContain("img-src 'self' data:");
});

it('runs Livewire in its CSP-safe mode', function () {
    expect(config('livewire.csp_safe'))->toBeTrue();

    $html = $this->get('/login')->getContent();

    expect($html)->toMatch('/livewire[^"]*\.js\?id=[^"]*-csp"/');
});
