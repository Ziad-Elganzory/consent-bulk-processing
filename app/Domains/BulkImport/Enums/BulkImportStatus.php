<?php

namespace App\Domains\BulkImport\Enums;

enum BulkImportStatus: string
{
    case AwaitingUpload = 'awaiting_upload';
    case Queued = 'queued';
    case Parsing = 'parsing';
    case Validating = 'validating';
    case Assembling = 'assembling';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::CompletedWithErrors, self::Failed], true);
    }
}
