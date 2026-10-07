<?php

namespace App\Domains\BulkImport\Services\Validation;

use App\Domains\BulkImport\Enums\ConsentStatus;

/**
 * Consent file, first schema version. A consent code may repeat across rows because each
 * version is stored as its own record, so a record is identified by code and version together.
 */
final class ConsentRowRules implements RowRules
{
    public const string CODE = 'Consent code';

    public const string NAME = 'Consent name';

    public const string DESCRIPTION = 'Description';

    public const string PURPOSE = 'Purpose';

    public const string VERSION = 'Version';

    public const string STATUS = 'Status';

    public function columns(): array
    {
        return [self::CODE, self::NAME, self::DESCRIPTION, self::PURPOSE, self::VERSION, self::STATUS];
    }

    public function errors(array $row): array
    {
        $errors = [
            ...$this->text($row, self::CODE, 'code', required: true),
            ...$this->text($row, self::NAME, 'name', required: true),
            ...$this->text($row, self::DESCRIPTION, 'description', required: false),
            ...$this->text($row, self::PURPOSE, 'purpose', required: true),
        ];

        if (filter_var($row[self::VERSION], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $errors[] = self::VERSION.' must be a positive whole number';
        }

        if (ConsentStatus::tryFrom(strtolower($row[self::STATUS])) === null) {
            $allowed = implode(', ', array_column(ConsentStatus::cases(), 'name'));
            $errors[] = self::STATUS." must be one of: {$allowed}";
        }

        return $errors;
    }

    public function uniqueKey(array $row): string
    {
        return $row[self::CODE].'|'.(int) $row[self::VERSION];
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private function text(array $row, string $column, string $limit, bool $required): array
    {
        if ($row[$column] === '') {
            return $required ? [$column.' is required'] : [];
        }

        $max = config("bulk-imports.validation.max_lengths.{$limit}");

        return mb_strlen($row[$column]) > $max ? ["{$column} is longer than {$max} characters"] : [];
    }
}
