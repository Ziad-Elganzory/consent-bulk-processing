<?php

namespace App\Filament\Resources\BulkImports\Pages;

use App\Filament\Resources\BulkImports\BulkImportResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBulkImports extends ListRecords
{
    protected static string $resource = BulkImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
