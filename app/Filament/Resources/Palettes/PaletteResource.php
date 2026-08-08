<?php

namespace App\Filament\Resources\Palettes;

use App\Filament\Resources\Palettes\Pages\CreatePalette;
use App\Filament\Resources\Palettes\Pages\EditPalette;
use App\Filament\Resources\Palettes\Pages\ListPalettes;
use App\Filament\Resources\Palettes\Schemas\PaletteForm;
use App\Filament\Resources\Palettes\Tables\PalettesTable;
use App\Models\Palette;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;
use App\Filament\Resources\Palettes\RelationManagers\ColorsRelationManager;
class PaletteResource extends Resource
{
    protected static ?string $model = Palette::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;
    protected static string|UnitEnum|null $navigationGroup = 'Base de conocimiento';
    protected static ?string $navigationLabel = 'Paletas';
    protected static ?string $modelLabel = 'paleta';
    protected static ?string $pluralModelLabel = 'Paletas';
    protected static ?int $navigationSort = 2;

    // protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PaletteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PalettesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ColorsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPalettes::route('/'),
            'create' => CreatePalette::route('/create'),
            'edit' => EditPalette::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
