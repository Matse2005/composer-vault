<?php

use App\Vault\Tokens\IpAllowlist;

it('accepts valid entries', function (string $entry) {
    expect(IpAllowlist::isValidEntry($entry))->toBeTrue();
})->with([
    'ipv4' => '203.0.113.7',
    'ipv6' => '2001:db8::1',
    'ipv6 loopback' => '::1',
    'ipv4 cidr' => '10.0.0.0/8',
    'ipv4 single host cidr' => '203.0.113.7/32',
    'ipv4 widest cidr' => '0.0.0.0/0',
    'ipv6 cidr' => '2001:db8::/32',
    'ipv6 single host cidr' => '::1/128',
]);

it('rejects invalid entries', function (string $entry) {
    expect(IpAllowlist::isValidEntry($entry))->toBeFalse();
})->with([
    'ipv4 prefix too long' => '10.0.0.0/33',
    'ipv6 prefix too long' => '2001:db8::/129',
    'hostname' => 'hello',
    'localhost' => 'localhost',
    'wildcard' => '*',
    'empty' => '',
    'missing prefix' => '10.0.0.0/',
    'negative prefix' => '10.0.0.0/-1',
    'text prefix' => '10.0.0.0/abc',
    'leading zero prefix' => '10.0.0.0/08',
    'two prefixes' => '10.0.0.0/8/8',
    'octet out of range' => '256.1.1.1',
    'too few octets' => '10.0.0',
    'leading space' => ' 10.0.0.1',
    'trailing newline' => "10.0.0.1\n",
    'range with a dash' => '10.0.0.1-10.0.0.9',
    'cidr with a hostname' => 'example.com/24',
]);

it('allows every address for an empty list', function (string $ip) {
    expect(IpAllowlist::allows([], $ip))->toBeTrue();
})->with(['203.0.113.7', '2001:db8::1']);

it('matches addresses and ranges', function (array $entries, string $ip, bool $expected) {
    expect(IpAllowlist::allows($entries, $ip))->toBe($expected);
})->with([
    'ipv4 exact match' => [['203.0.113.7'], '203.0.113.7', true],
    'ipv4 exact miss' => [['203.0.113.7'], '203.0.113.8', false],
    'ipv4 cidr match' => [['10.0.0.0/8'], '10.200.3.4', true],
    'ipv4 cidr miss' => [['10.0.0.0/8'], '11.0.0.1', false],
    'ipv4 cidr boundary low' => [['192.168.1.0/24'], '192.168.1.0', true],
    'ipv4 cidr boundary high' => [['192.168.1.0/24'], '192.168.1.255', true],
    'ipv4 cidr just outside' => [['192.168.1.0/24'], '192.168.2.0', false],
    'ipv6 exact match' => [['2001:db8::1'], '2001:db8::1', true],
    'ipv6 exact miss' => [['2001:db8::1'], '2001:db8::2', false],
    'ipv6 cidr match' => [['2001:db8::/32'], '2001:db8:ffff::1', true],
    'ipv6 cidr miss' => [['2001:db8::/32'], '2001:db9::1', false],
    'one of several entries' => [['198.51.100.1', '10.0.0.0/8'], '10.1.1.1', true],
    'ipv4 entry does not match ipv6' => [['10.0.0.0/8'], '2001:db8::1', false],
    'ipv6 entry does not match ipv4' => [['2001:db8::/32'], '10.0.0.1', false],
    'garbage address is denied' => [['10.0.0.0/8'], 'not-an-ip', false],
    'empty address is denied' => [['10.0.0.0/8'], '', false],
]);
