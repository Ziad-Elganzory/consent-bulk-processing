<?php

use App\Domains\BulkImport\Services\Validation\ConsentRowRules;

function consentRow(array $overrides = []): array
{
    return [
        'Consent code' => 'MARKETING',
        'Consent name' => 'Marketing emails',
        'Description' => 'Newsletters and offers',
        'Purpose' => 'Marketing',
        'Version' => '2',
        'Status' => 'Active',
        ...$overrides,
    ];
}

it('accepts a complete row', function (): void {
    expect((new ConsentRowRules)->errors(consentRow()))->toBe([]);
});

it('accepts a row without a description and with the status in any case', function (): void {
    expect((new ConsentRowRules)->errors(consentRow(['Description' => '', 'Status' => 'INACTIVE'])))->toBe([]);
});

it('rejects a row with a missing or invalid cell', function (array $overrides, string $error): void {
    expect((new ConsentRowRules)->errors(consentRow($overrides)))->toBe([$error]);
})->with([
    'no code' => [['Consent code' => ''], 'Consent code is required'],
    'no name' => [['Consent name' => ''], 'Consent name is required'],
    'no purpose' => [['Purpose' => ''], 'Purpose is required'],
    'no version' => [['Version' => ''], 'Version must be a positive whole number'],
    'zero version' => [['Version' => '0'], 'Version must be a positive whole number'],
    'decimal version' => [['Version' => '1.5'], 'Version must be a positive whole number'],
    'unknown status' => [['Status' => 'Pending'], 'Status must be one of: Active, Inactive'],
]);

it('rejects a cell longer than its limit from config', function (): void {
    config(['bulk-imports.validation.max_lengths.code' => 5]);

    expect((new ConsentRowRules)->errors(consentRow(['Consent code' => 'TOO-LONG'])))
        ->toBe(['Consent code is longer than 5 characters']);
});

it('identifies a record by code and version, so a new version of a code is not a duplicate', function (): void {
    $rules = new ConsentRowRules;

    expect($rules->uniqueKey(consentRow(['Version' => '2'])))->not->toBe($rules->uniqueKey(consentRow(['Version' => '3'])))
        ->and($rules->uniqueKey(consentRow(['Version' => '2'])))->toBe($rules->uniqueKey(consentRow(['Version' => '02'])));
});
