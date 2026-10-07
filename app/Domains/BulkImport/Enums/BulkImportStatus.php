<?php

namespace App\Domains\BulkImport\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BulkImportStatus: string implements HasColor, HasLabel
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

    public function getLabel(): string
    {
        return match ($this) {
            self::AwaitingUpload => 'Awaiting upload',
            self::Queued => 'Queued',
            self::Parsing => 'Splitting file',
            self::Validating => 'Validating',
            self::Assembling => 'Building result',
            self::Completed => 'Completed',
            self::CompletedWithErrors => 'Completed with rejected rows',
            self::Failed => 'Failed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AwaitingUpload, self::Queued => 'gray',
            self::Parsing, self::Validating, self::Assembling => 'info',
            self::Completed => 'success',
            self::CompletedWithErrors => 'warning',
            self::Failed => 'danger',
        };
    }
}
