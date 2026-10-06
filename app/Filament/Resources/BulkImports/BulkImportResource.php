<?php

namespace App\Filament\Resources\BulkImports;

use App\Domains\BulkImport\Models\BulkImport;
use App\Filament\Resources\BulkImports\Pages\CreateBulkImport;
use App\Filament\Resources\BulkImports\Pages\ListBulkImports;
use App\Filament\Resources\BulkImports\Schemas\BulkImportForm;
use App\Filament\Resources\BulkImports\Schemas\BulkImportInfolist;
use App\Filament\Resources\BulkImports\Tables\BulkImportsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BulkImportResource extends Resource
{
    protected static ?string $model = BulkImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'original_filename';

    public static function form(Schema $schema): Schema
    {
        return BulkImportForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return BulkImportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BulkImportsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBulkImports::route('/'),
            'create' => CreateBulkImport::route('/create'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id());
    }
}
