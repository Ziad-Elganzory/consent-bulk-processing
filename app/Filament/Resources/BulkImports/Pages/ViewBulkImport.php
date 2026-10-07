<?php

namespace App\Filament\Resources\BulkImports\Pages;

use App\Domains\BulkImport\Models\BulkImport;
use App\Filament\Resources\BulkImports\BulkImportResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewBulkImport extends ViewRecord
{
    protected static string $resource = BulkImportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadResult')
                ->label('Download result')
                ->icon(Heroicon::ArrowDownTray)
                ->visible(fn (BulkImport $record): bool => filled($record->output_object_key))
                ->action(fn (BulkImport $record): StreamedResponse => $this->download($record->output_object_key, $this->downloadName($record, 'result'))),

            Action::make('downloadRejectedRows')
                ->label('Download rejected rows')
                ->icon(Heroicon::ArrowDownTray)
                ->color('warning')
                ->visible(fn (BulkImport $record): bool => filled($record->error_object_key))
                ->action(fn (BulkImport $record): StreamedResponse => $this->download($record->error_object_key, $this->downloadName($record, 'rejected-rows'))),
        ];
    }

    private function download(string $objectKey, string $filename): StreamedResponse
    {
        return Storage::disk(config('bulk-imports.disk'))->download($objectKey, $filename);
    }

    private function downloadName(BulkImport $record, string $suffix): string
    {
        $name = pathinfo((string) $record->original_filename, PATHINFO_FILENAME);

        return ($name === '' ? 'import' : $name)."-{$suffix}.csv";
    }
}
