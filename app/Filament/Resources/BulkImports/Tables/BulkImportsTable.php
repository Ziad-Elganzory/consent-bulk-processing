<?php

namespace App\Filament\Resources\BulkImports\Tables;

use App\Domains\BulkImport\Models\BulkImportChunk;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BulkImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount([
                'chunks',
                'chunks as finished_chunks_count' => fn (Builder $chunks) => $chunks->whereIn('status', BulkImportChunk::finishedStatuses()),
            ]))
            ->columns([
                TextColumn::make('original_filename')
                    ->label('File')
                    ->searchable(),

                TextColumn::make('status')
                    ->badge(),

                ViewColumn::make('progress')
                    ->label('Progress')
                    ->view('filament.bulk-imports.progress-bar'),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('5s')
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
