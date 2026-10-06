<?php

namespace App\Filament\Resources\BulkImports\Pages;

use App\Filament\Resources\BulkImports\BulkImportResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditBulkImport extends EditRecord
{
    protected static string $resource = BulkImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
