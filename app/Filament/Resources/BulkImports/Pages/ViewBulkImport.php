<?php

namespace App\Filament\Resources\BulkImports\Pages;

use App\Filament\Resources\BulkImports\BulkImportResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * Not registered in BulkImportResource::getPages() yet. It becomes the import
 * status page, together with BulkImportInfolist, in the dashboard step.
 */
class ViewBulkImport extends ViewRecord
{
    protected static string $resource = BulkImportResource::class;
}
