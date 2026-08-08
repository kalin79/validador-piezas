<?php

namespace App\Filament\Resources\RuleSets;

use App\Filament\Resources\RuleSets\Pages\CreateRuleSet;
use App\Filament\Resources\RuleSets\Pages\EditRuleSet;
use App\Filament\Resources\RuleSets\Pages\ListRuleSets;
use App\Filament\Resources\RuleSets\Schemas\RuleSetForm;
use App\Filament\Resources\RuleSets\Tables\RuleSetsTable;
use App\Models\RuleSet;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;
use App\Filament\Resources\RuleSets\RelationManagers\RulesRelationManager;
class RuleSetResource extends Resource
{
    protected static ?string $model = RuleSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;
    protected static string|UnitEnum|null $navigationGroup = 'Base de conocimiento';
    protected static ?string $navigationLabel = 'Conjuntos de reglas';
    protected static ?string $modelLabel = 'conjunto de regla';
    protected static ?string $pluralModelLabel = 'Conjuntos de reglas';
    protected static ?int $navigationSort = 1;

    // protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return RuleSetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RuleSetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RulesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRuleSets::route('/'),
            'create' => CreateRuleSet::route('/create'),
            'edit' => EditRuleSet::route('/{record}/edit'),
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
