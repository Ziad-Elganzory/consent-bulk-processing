<?php

namespace App\Filament\Resources\BulkImports\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BulkImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('original_filename')
                    ->label('File')
                    ->searchable(),

                TextColumn::make('status')
                    ->badge(),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->toolbarActions([]);
    }
}
