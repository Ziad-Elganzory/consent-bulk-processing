<?php

use App\Infrastructure\Messaging\Protocol\MessageFields;

it('reads a text field', function (): void {
    expect((new MessageFields(['name' => 'value']))->text('name'))->toBe('value');
});

it('rejects a text field that is missing, empty or not a string', function (array $fields, string $error): void {
    expect(fn () => (new MessageFields($fields))->text('name'))->toThrow(InvalidArgumentException::class, $error);
})->with([
    'missing' => [[], 'Field [name] is missing or is not a string.'],
    'not a string' => [['name' => 5], 'Field [name] is missing or is not a string.'],
    'empty' => [['name' => ''], 'Field [name] must not be empty.'],
]);

it('passes a non-empty value through and rejects an empty one', function (): void {
    expect(MessageFields::nonEmpty('value', 'name'))->toBe('value');

    MessageFields::nonEmpty('', 'name');
})->throws(InvalidArgumentException::class, 'Field [name] must not be empty.');
