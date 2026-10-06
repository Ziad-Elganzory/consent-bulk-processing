<?php

namespace App\Filament\Resources\BulkImports\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;
use Livewire\Component as LivewireComponent;

class BulkImportForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                FileUpload::make('source_object_key')
                    ->label('CSV file')
                    ->required()
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/vnd.ms-excel',
                    ])
                    ->rule('extensions:csv')
                    ->maxSize(config('bulk-imports.max_file_size_kb'))
                    ->disk(config('bulk-imports.disk'))
                    ->directory(fn (LivewireComponent $livewire): string => "consent/import-{$livewire->uploadId}/source"
                    )
                    ->getUploadedFileNameForStorageUsing(fn (): string => 'source.csv')
                    ->visibility('private')
                    ->preventFilePathTampering()
                    ->storeFileNamesIn('original_filename'),
            ]);
    }
}
