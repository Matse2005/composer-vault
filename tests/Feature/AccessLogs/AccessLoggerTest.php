<?php

use App\Enums\AccessResult;
use App\Models\AccessLog;
use App\Models\AccessToken;
use App\Models\Project;
use App\Vault\AccessLogger;
use App\Vault\RequestAttributes;
use Database\Factories\AccessTokenFactory;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/**
 * @param  array<string, mixed>  $attributes
 * @param  array<string, string>  $server
 */
function vaultRequest(array $attributes = [], array $server = [], string $userAgent = 'composer/2.8'): Request
{
    $request = Request::create('/api/v1/projects/shop/composer-auth', 'GET', server: $server + [
        'REMOTE_ADDR' => '203.0.113.9',
        'HTTP_USER_AGENT' => $userAgent,
    ]);

    foreach ($attributes as $key => $value) {
        $request->attributes->set($key, $value);
    }

    return $request;
}

it('writes one row with the request details for every access result', function (AccessResult $result) {
    $token = AccessToken::factory()->create();
    $project = Project::factory()->create();

    app(AccessLogger::class)->record(vaultRequest([
        RequestAttributes::ACCESS_RESULT => $result,
        RequestAttributes::ACCESS_TOKEN => $token,
        RequestAttributes::PROJECT => $project,
        RequestAttributes::REQUESTED_SLUG => $project->slug,
    ]));

    $log = AccessLog::query()->sole();

    expect($log->result)->toBe($result)
        ->and($log->access_token_id)->toBe($token->id)
        ->and($log->project_id)->toBe($project->id)
        ->and($log->requested_slug)->toBe($project->slug)
        ->and($log->ip)->toBe('203.0.113.9')
        ->and($log->user_agent)->toBe('composer/2.8')
        ->and($log->created_at)->not->toBeNull();
})->with(AccessResult::cases());

it('writes a row without token and project when the middleware stopped early', function () {
    app(AccessLogger::class)->record(vaultRequest());

    $log = AccessLog::query()->sole();

    expect($log->result)->toBe(AccessResult::UnknownToken)
        ->and($log->access_token_id)->toBeNull()
        ->and($log->project_id)->toBeNull()
        ->and($log->requested_slug)->toBeNull();
});

it('truncates the requested slug and the user agent', function () {
    app(AccessLogger::class)->record(vaultRequest(
        [RequestAttributes::REQUESTED_SLUG => str_repeat('a', 100)],
        userAgent: str_repeat('u', 700),
    ));

    $log = AccessLog::query()->sole();

    expect($log->requested_slug)->toHaveLength(64)
        ->and($log->user_agent)->toHaveLength(512);
});

it('truncates multibyte values without breaking them', function () {
    app(AccessLogger::class)->record(vaultRequest(userAgent: str_repeat('é', 600)));

    expect(AccessLog::query()->sole()->user_agent)->toBe(str_repeat('é', 512));
});

it('stores no null user agent as an empty value', function () {
    $request = vaultRequest();
    $request->headers->remove('User-Agent');

    app(AccessLogger::class)->record($request);

    expect(AccessLog::query()->sole()->user_agent)->toBeNull();
});

it('never writes the bearer value or a part of it', function () {
    $token = AccessToken::factory()->withPlainToken()->create();
    $plain = AccessTokenFactory::plainTokenFor($token);

    app(AccessLogger::class)->record(vaultRequest(
        [
            RequestAttributes::ACCESS_RESULT => AccessResult::Ok,
            RequestAttributes::ACCESS_TOKEN => $token,
        ],
        ['HTTP_AUTHORIZATION' => 'Bearer '.$plain],
    ));

    $row = json_encode((array) DB::table('access_logs')->first());

    expect($row)->not->toContain($plain)
        ->not->toContain(substr($plain, 3))
        ->not->toContain(substr($plain, 11))
        ->not->toContain('Bearer');
});

it('reports a failed write without throwing and writes nothing', function () {
    Exceptions::fake();
    $unsavedToken = (new AccessToken)->forceFill(['id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ']);

    app(AccessLogger::class)->record(vaultRequest([
        RequestAttributes::ACCESS_RESULT => AccessResult::Ok,
        RequestAttributes::ACCESS_TOKEN => $unsavedToken,
    ]));

    expect(AccessLog::query()->count())->toBe(0);
    Exceptions::assertReported(QueryException::class);
});
