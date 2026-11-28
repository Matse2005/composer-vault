<?php

use App\Enums\CredentialType;
use App\Exceptions\Vault\DecryptionFailedException;
use App\Models\Credential;
use App\Models\Project;
use App\Vault\ComposerAuthBuilder;
use Illuminate\Support\Facades\DB;

function projectWith(Credential ...$credentials): Project
{
    $project = Project::factory()->create();

    foreach ($credentials as $credential) {
        $credential->project()->associate($project)->save();
    }

    return $project;
}

it('builds an empty array for a project without credentials', function () {
    expect(app(ComposerAuthBuilder::class)->build(Project::factory()->create()))->toBe([]);
});

it('puts each credential type in its own structure', function () {
    $project = projectWith(
        Credential::factory()->ofType(CredentialType::HttpBasic)->forHost('repo.magento.com')->withSecret('private-key')->create(['username' => 'public-key']),
        Credential::factory()->ofType(CredentialType::Bearer)->forHost('packages.example.com')->withSecret('bearer-token')->create(),
        Credential::factory()->ofType(CredentialType::GithubOauth)->forHost('github.com')->withSecret('github-token')->create(),
        Credential::factory()->ofType(CredentialType::GitlabToken)->forHost('gitlab.com')->withSecret('gitlab-token')->create(),
    );

    $body = app(ComposerAuthBuilder::class)->build($project);

    expect($body)->toBe([
        'http-basic' => ['repo.magento.com' => ['username' => 'public-key', 'password' => 'private-key']],
        'bearer' => ['packages.example.com' => 'bearer-token'],
        'github-oauth' => ['github.com' => 'github-token'],
        'gitlab-token' => ['gitlab.com' => 'gitlab-token'],
    ]);
    assertComposerAuthShape($body);
});

it('only includes the types that have an entry', function () {
    $project = projectWith(Credential::factory()->ofType(CredentialType::GithubOauth)->forHost('github.com')->withSecret('token')->create());

    expect(array_keys(app(ComposerAuthBuilder::class)->build($project)))->toBe(['github-oauth']);
});

it('orders the hosts of a type alphabetically', function () {
    $project = projectWith(
        Credential::factory()->ofType(CredentialType::Bearer)->forHost('zeta.example.com')->withSecret('z')->create(),
        Credential::factory()->ofType(CredentialType::Bearer)->forHost('alpha.example.com')->withSecret('a')->create(),
        Credential::factory()->ofType(CredentialType::Bearer)->forHost('mid.example.com')->withSecret('m')->create(),
    );

    expect(array_keys(app(ComposerAuthBuilder::class)->build($project)['bearer']))
        ->toBe(['alpha.example.com', 'mid.example.com', 'zeta.example.com']);
});

it('gives each project its own credential for the same host', function () {
    $first = projectWith(Credential::factory()->forHost('repo.magento.com')->withSecret('first-secret')->create(['username' => 'first-user']));
    $second = projectWith(Credential::factory()->forHost('repo.magento.com')->withSecret('second-secret')->create(['username' => 'second-user']));

    $builder = app(ComposerAuthBuilder::class);

    expect($builder->build($first)['http-basic']['repo.magento.com'])->toBe(['username' => 'first-user', 'password' => 'first-secret'])
        ->and($builder->build($second)['http-basic']['repo.magento.com'])->toBe(['username' => 'second-user', 'password' => 'second-secret']);
});

it('never includes credentials of other projects', function () {
    $project = projectWith(Credential::factory()->ofType(CredentialType::Bearer)->forHost('mine.example.com')->withSecret('mine')->create());
    projectWith(Credential::factory()->ofType(CredentialType::Bearer)->forHost('other.example.com')->withSecret('other')->create());
    Credential::factory()->ofType(CredentialType::Bearer)->forHost('unowned.example.com')->withSecret('unowned')->create();

    expect(app(ComposerAuthBuilder::class)->build($project))->toBe(['bearer' => ['mine.example.com' => 'mine']]);
});

it('fails as a whole when one secret cannot be decrypted', function () {
    $project = Project::factory()->create();
    Credential::factory()->for($project)->forHost('good.example.com')->withSecret('good-secret')->create();
    $bad = Credential::factory()->for($project)->forHost('bad.example.com')->withSecret('bad-secret')->create();
    DB::table('credentials')->where('id', $bad->id)->update(['secret_ciphertext' => base64_encode('corrupted')]);

    app(ComposerAuthBuilder::class)->build($project);
})->throws(DecryptionFailedException::class);
