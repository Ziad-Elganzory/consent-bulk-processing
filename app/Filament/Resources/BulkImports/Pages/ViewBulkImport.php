<?php

namespace App\Filament\Resources\BulkImports\Pages;

use App\Filament\Resources\BulkImports\BulkImportResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBulkImport extends ViewRecord
{
    protected static string $resource = BulkImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
