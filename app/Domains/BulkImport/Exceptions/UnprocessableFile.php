<?php

namespace App\Domains\BulkImport\Exceptions;

use RuntimeException;

/**
 * The uploaded file can never be processed, so retrying is pointless. The import is
 * marked failed instead.
 */
class UnprocessableFile extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self("The file is not processable: {$reason}.");
    }
}
