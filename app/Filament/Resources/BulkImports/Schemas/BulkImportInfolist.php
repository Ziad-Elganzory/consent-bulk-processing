<?php

namespace App\Filament\Resources\BulkImports\Schemas;

use App\Domains\BulkImport\Models\BulkImport;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class BulkImportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Import')
                    ->poll('3s')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('original_filename')
                            ->label('File'),

                        TextEntry::make('status')
                            ->badge(),

                        TextEntry::make('source_size_bytes')
                            ->label('Size')
                            ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Number::fileSize($state)),

                        ViewEntry::make('progress')
                            ->label('Progress')
                            ->view('filament.bulk-imports.progress-bar')
                            ->columnSpanFull(),

                        TextEntry::make('chunks_progress')
                            ->label('Chunks validated')
                            ->state(fn (BulkImport $record): string => "{$record->chunksFinished()} of {$record->chunkTotal()}"),

                        TextEntry::make('valid_rows')
                            ->label('Valid rows')
                            ->state(fn (BulkImport $record): int => (int) $record->chunks()->sum('valid_rows')),

                        TextEntry::make('rejected_rows')
                            ->label('Rejected rows')
                            ->state(fn (BulkImport $record): int => (int) $record->chunks()->sum('invalid_rows')),

                        TextEntry::make('created_at')
                            ->dateTime(),

                        TextEntry::make('completed_at')
                            ->dateTime()
                            ->placeholder('Not finished yet'),

                        TextEntry::make('failure_message')
                            ->label('Notes')
                            ->visible(fn (BulkImport $record): bool => filled($record->failure_message))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
