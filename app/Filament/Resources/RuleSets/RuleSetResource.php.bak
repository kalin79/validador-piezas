<?php

namespace App\Filament\Resources\RuleSets;

use App\Filament\Resources\RuleSets\Pages\CreateRuleSet;
use App\Filament\Resources\RuleSets\Pages\EditRuleSet;
use App\Filament\Resources\RuleSets\Pages\ListRuleSets;
use App\Filament\Resources\RuleSets\Pages\ViewRuleSet;
use App\Filament\Resources\RuleSets\Schemas\RuleSetInfolist;
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

    public static function infolist(Schema $schema): Schema
    {
        return RuleSetInfolist::configure($schema);
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
            'view' => ViewRuleSet::route('/{record}'),
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



    /**
     * El dueno es polimorfico manual, asi que el filtro es en dos ramas: los
     * conjuntos de cliente contra los clientes accesibles, los de marca contra
     * las marcas accesibles.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user === null || $user->hasGlobalAccess()) {
            return $query;
        }

        $clientes = $user->accessibleClientIds();
        $marcas = $user->accessibleBrandIds();

        return $query->where(function (Builder $q) use ($clientes, $marcas): void {
            $q->where(function (Builder $q2) use ($clientes): void {
                $q2->where('owner_type', \App\Enums\RuleSetOwnerType::Client->value)
                    ->whereIn('owner_id', $clientes);
            })->orWhere(function (Builder $q2) use ($marcas): void {
                $q2->where('owner_type', \App\Enums\RuleSetOwnerType::Brand->value)
                    ->whereIn('owner_id', $marcas);
            });
        });
    }
}
