<?php

use App\Vault\HostName;

it('normalizes hosts', function (string $input, string $expected) {
    expect(HostName::normalize($input))->toBe($expected);
})->with([
    'scheme, uppercase and trailing slash' => ['https://Repo.Magento.com/', 'repo.magento.com'],
    'plain host' => ['repo.magento.com', 'repo.magento.com'],
    'uppercase' => ['GitHub.com', 'github.com'],
    'surrounding whitespace' => ["  gitlab.com \n", 'gitlab.com'],
    'path after the host' => ['https://packages.example.com/composer/satis', 'packages.example.com'],
    'http scheme' => ['http://localhost', 'localhost'],
    'subdomain with hyphen' => ['my-repo.example.co.uk', 'my-repo.example.co.uk'],
]);

it('rejects invalid hosts', function (string $input) {
    HostName::normalize($input);
})->with([
    'empty string' => [''],
    'only whitespace' => ['   '],
    'only a scheme' => ['https://'],
    'port' => ['repo.magento.com:443'],
    'port with scheme' => ['https://repo.magento.com:8443/'],
    'space inside' => ['repo magento.com'],
    'credentials in the url' => ['user:pass@repo.magento.com'],
    'underscore' => ['my_repo.example.com'],
    'leading dot' => ['.example.com'],
    'double dot' => ['example..com'],
])->throws(InvalidArgumentException::class);
