<?php

use App\Infrastructure\Messaging\Protocol\MessageData;

it('reads required strings', function (): void {
    expect(MessageData::requiredString(['a' => 'x'], 'a'))->toBe('x');
});

it('rejects invalid required strings', function (array $data): void {
    MessageData::requiredString($data, 'a');
})->throws(InvalidArgumentException::class)->with([
    'missing' => [[]],
    'empty' => [['a' => '']],
    'not a string' => [['a' => 1]],
]);

it('reads required integers', function (): void {
    expect(MessageData::requiredInt(['n' => 3], 'n', minimum: 1))->toBe(3);
});

it('rejects invalid required integers', function (array $data): void {
    MessageData::requiredInt($data, 'n', minimum: 1);
})->throws(InvalidArgumentException::class)->with([
    'missing' => [[]],
    'string' => [['n' => '3']],
    'below minimum' => [['n' => 0]],
]);

it('reads nullable strings but requires the key', function (): void {
    expect(MessageData::nullableString(['a' => null], 'a'))->toBeNull()
        ->and(MessageData::nullableString(['a' => 'x'], 'a'))->toBe('x');

    MessageData::nullableString([], 'a');
})->throws(InvalidArgumentException::class);

it('rejects empty and non string values for nullable strings', function (array $data): void {
    MessageData::nullableString($data, 'a');
})->throws(InvalidArgumentException::class)->with([
    'empty' => [['a' => '']],
    'integer' => [['a' => 1]],
]);
