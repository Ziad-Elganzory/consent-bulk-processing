<?php

namespace App\Domains\BulkImport\Services\Validation;

/**
 * The schema a bulk import file must follow. Swap the implementation to import another kind of file.
 */
interface RowRules
{
    /**
     * Header names the file must contain.
     *
     * @return list<string>
     */
    public function columns(): array;

    /**
     * What is wrong with a row, empty when it is valid.
     *
     * @param  array<string, string>  $row  trimmed cells, keyed by the names from columns()
     * @return list<string>
     */
    public function errors(array $row): array;

    /**
     * Identifies the record a row describes, so two rows with the same key are duplicates.
     *
     * @param  array<string, string>  $row
     */
    public function uniqueKey(array $row): string;
}
