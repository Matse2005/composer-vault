<?php

use App\Enums\CredentialType;

it('has the composer auth type values', function () {
    expect(array_column(CredentialType::cases(), 'value'))
        ->toBe(['http-basic', 'bearer', 'github-oauth', 'gitlab-token']);
});

it('requires a username only for http-basic', function (CredentialType $type, bool $requiresUsername) {
    expect($type->requiresUsername())->toBe($requiresUsername);
})->with([
    [CredentialType::HttpBasic, true],
    [CredentialType::Bearer, false],
    [CredentialType::GithubOauth, false],
    [CredentialType::GitlabToken, false],
]);

it('has a label for every type', function (CredentialType $type) {
    expect($type->label())->toBeString()->not->toBeEmpty();
})->with(CredentialType::cases());
