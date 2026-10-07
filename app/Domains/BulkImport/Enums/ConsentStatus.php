<?php

namespace App\Domains\BulkImport\Enums;

/**
 * The values the Status column of a consent file may hold. Add a case to accept a new one.
 */
enum ConsentStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
