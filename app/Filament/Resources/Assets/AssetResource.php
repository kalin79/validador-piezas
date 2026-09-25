<?php

declare(strict_types=1);

namespace App\Filament\Resources\Assets;

use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Tables\AssetsTable;
use App\Models\Asset;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Listado transversal de piezas validadas.
 *
 * El modulo de cargas responde "que subi en esta campana". Este responde las
 * otras preguntas, que son las que aparecen cuando el sistema lleva meses en
 * uso: donde quedo aquella pieza, que se rechazo este mes en Pro, que esta
 * llegando desde el plugin de Figma.
 *
 * Es de solo lectura a proposito. Las piezas entran por una carga o por la
 * API; crearlas sueltas desde aqui dejaria piezas sin contexto de origen.
 */
class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Operación';

    protected static ?string $navigationLabel = 'Piezas validadas';

    protected static ?string $modelLabel = 'pieza';

    protected static ?string $pluralModelLabel = 'Piezas';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'original_filename';

    public static function table(Table $table): Table
    {
        return AssetsTable::configure($table);
    }

    /**
     * Nadie ve piezas de marcas a las que no tiene acceso.
     *
     * Se aplica en la consulta base y no en la tabla: filtrar la vista deja
     * abierta la ruta directa al registro. Aqui la restriccion vale para
     * cualquier consulta que salga de este recurso.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        $query = parent::getEloquentQuery()
            ->whereIn('brand_id', $user->accessibleBrandIds());

        // Quien solo tiene submission.view_own ve sus piezas, no las de sus
        // companeros de marca. Antes la policy lo exigia en la ficha, pero el
        // listado mostraba todo.
        if (! $user->hasPermissionTo('submission.view_any') && ! $user->hasPermissionTo('submission.view_brand')) {
            $query->whereHas('submission', fn (Builder $q): Builder => $q->where('user_id', $user->id));
        }

        return $query;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAssets::route('/'),
        ];
    }
}
