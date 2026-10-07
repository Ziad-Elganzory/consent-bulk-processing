<?php

namespace App\Filament\Resources\BulkImports\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChunksRelationManager extends RelationManager
{
    protected static string $relationship = 'chunks';

    protected static ?string $title = 'Chunks';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('sequence')
            ->columns([
                TextColumn::make('sequence')
                    ->label('Chunk')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge(),

                TextColumn::make('valid_rows')
                    ->label('Valid rows')
                    ->numeric(),

                TextColumn::make('invalid_rows')
                    ->label('Rejected rows')
                    ->numeric(),

                TextColumn::make('failure_message')
                    ->label('Notes')
                    ->placeholder('-')
                    ->wrap(),
            ])
            ->defaultSort('sequence')
            ->poll('5s');
    }
}
