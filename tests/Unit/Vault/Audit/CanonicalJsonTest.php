<?php

use App\Vault\Audit\CanonicalJson;

it('gives the same output for the same data in a different key order', function () {
    $first = ['b' => 1, 'a' => ['y' => true, 'x' => null], 'c' => 'text'];
    $second = ['c' => 'text', 'a' => ['x' => null, 'y' => true], 'b' => 1];

    expect(CanonicalJson::encode($first))->toBe(CanonicalJson::encode($second))
        ->toBe('{"a":{"x":null,"y":true},"b":1,"c":"text"}');
});

it('keeps the order of lists', function () {
    expect(CanonicalJson::encode(['ids' => ['b', 'a', 'c']]))->toBe('{"ids":["b","a","c"]}');
});

it('sorts the keys of associative arrays inside lists', function () {
    expect(CanonicalJson::encode(['rows' => [['b' => 1, 'a' => 2], ['d' => 3, 'c' => 4]]]))
        ->toBe('{"rows":[{"a":2,"b":1},{"c":4,"d":3}]}');
});

it('encodes an empty array as an empty list', function () {
    expect(CanonicalJson::encode([]))->toBe('[]');
});

it('does not escape slashes or unicode', function () {
    expect(CanonicalJson::encode(['host' => 'repo.magento.com/path', 'name' => 'Zoë 密码']))
        ->toBe('{"host":"repo.magento.com/path","name":"Zoë 密码"}');
});

it('keeps integers, booleans and null as they are', function () {
    expect(CanonicalJson::encode(['int' => 7, 'zero' => 0, 'yes' => true, 'no' => false, 'none' => null, 'numeric' => '7']))
        ->toBe('{"int":7,"no":false,"none":null,"numeric":"7","yes":true,"zero":0}');
});

it('rejects values that are not strings, integers, booleans, null or arrays', function (mixed $value) {
    CanonicalJson::encode(['value' => $value]);
})->with([
    'float' => [1.5],
    'object' => [fn () => new stdClass],
    'closure' => [fn () => fn () => 1],
    'nested float' => [[['deep' => 0.1]]],
])->throws(InvalidArgumentException::class);

it('rejects invalid utf-8', function () {
    CanonicalJson::encode(['value' => "\xB1\x31"]);
})->throws(JsonException::class);
