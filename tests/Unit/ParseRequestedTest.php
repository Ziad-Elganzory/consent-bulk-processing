<?php

use App\Infrastructure\Messaging\Protocol\Messages\ParseRequested;

it('uses the parse routing key as its type', function (): void {
    expect(ParseRequested::type())->toBe('consent.parse.requested');
});

it('round trips through its data', function (): void {
    $message = new ParseRequested('import-1', 'consent/import-1/source/source.csv');

    $restored = ParseRequested::fromData($message->data());

    expect($restored->bulkImportId)->toBe('import-1')
        ->and($restored->sourceObjectKey)->toBe('consent/import-1/source/source.csv');
});

it('rejects empty or missing fields', function (array $data): void {
    ParseRequested::fromData($data);
})->throws(InvalidArgumentException::class)->with([
    'missing import id' => [['source_object_key' => 'key']],
    'empty import id' => [['bulk_import_id' => '', 'source_object_key' => 'key']],
    'missing object key' => [['bulk_import_id' => 'import-1']],
    'non string object key' => [['bulk_import_id' => 'import-1', 'source_object_key' => 5]],
]);

it('rejects empty values when constructed directly', function (): void {
    new ParseRequested('', 'key');
})->throws(InvalidArgumentException::class);
