<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptTemplates;

use App\Filament\Resources\PromptTemplates\Pages\CreatePromptTemplate;
use App\Filament\Resources\PromptTemplates\Pages\EditPromptTemplate;
use App\Filament\Resources\PromptTemplates\Pages\ListPromptTemplates;use App\Filament\Resources\PromptTemplates\Pages\ViewPromptTemplate;
use App\Filament\Resources\PromptTemplates\Schemas\PromptTemplateForm;
use App\Filament\Resources\PromptTemplates\Tables\PromptTemplatesTable;
use App\Models\PromptTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Las instrucciones que recibe el modelo, versionadas como las reglas.
 *
 * El prompt es criterio, no configuracion tecnica: define como se juzga una
 * pieza tanto como el enunciado de una regla. Tenerlo solo en un seeder lo
 * volvia invisible, y un criterio que nadie puede leer no se puede auditar
 * ni discutir.
 *
 * Se sigue el mismo contrato que los conjuntos de reglas: publicado es
 * inmutable, cambiar exige una version nueva, y cada ejecucion guarda con
 * cual se hizo.
 */
class PromptTemplateResource extends Resource
{
    protected static ?string $model = PromptTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Base de conocimiento';

    protected static ?string $navigationLabel = 'Instrucciones del modelo';

    protected static ?string $modelLabel = 'instruccion';

    protected static ?string $pluralModelLabel = 'instrucciones';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return PromptTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromptTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPromptTemplates::route('/'),
            'create' => CreatePromptTemplate::route('/create'),
            'view' => ViewPromptTemplate::route('/{record}'),
            'edit' => EditPromptTemplate::route('/{record}/edit'),
        ];
    }

    /**
     * Las plantillas generales (client_id nulo) afectan a todos los clientes:
     * solo las ve quien tiene alcance global.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user === null || $user->hasGlobalAccess()) {
            return $query;
        }

        return $query->whereIn('client_id', $user->accessibleClientIds());
    }
}
