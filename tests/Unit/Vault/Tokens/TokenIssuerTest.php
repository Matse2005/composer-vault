<?php

use App\Vault\Tokens\TokenIssuer;

it('generates tokens of cv_ plus 40 alphanumeric characters', function () {
    $issuer = new TokenIssuer;

    foreach (range(1, 25) as $ignored) {
        expect($issuer->generatePlainToken())->toMatch('/^cv_[A-Za-z0-9]{40}$/');
    }
});

it('generates a different token every time', function () {
    $issuer = new TokenIssuer;
    $tokens = collect(range(1, 100))->map(fn () => $issuer->generatePlainToken());

    expect($tokens->unique())->toHaveCount(100);
});

it('uses upper case, lower case and digits', function () {
    $issuer = new TokenIssuer;
    $characters = collect(range(1, 40))->map(fn () => substr($issuer->generatePlainToken(), 3))->implode('');

    expect($characters)->toMatch('/[a-z]/')->toMatch('/[A-Z]/')->toMatch('/[0-9]/');
});

it('takes the prefix from the first 8 characters after cv_', function () {
    $plain = 'cv_'.'ABCDEFGH'.str_repeat('x', 32);

    expect(TokenIssuer::prefix($plain))->toBe('ABCDEFGH');
});

it('hashes with hex sha-256', function () {
    $plain = 'cv_'.str_repeat('a', 40);

    expect(TokenIssuer::hash($plain))->toBe(hash('sha256', $plain))->toMatch('/^[0-9a-f]{64}$/');
});
