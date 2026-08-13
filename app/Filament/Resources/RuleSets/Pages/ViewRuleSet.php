<?php

declare(strict_types=1);

namespace App\Filament\Resources\RuleSets\Pages;

use App\Filament\Resources\RuleSets\RuleSetResource;
use App\Models\RuleSet;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Pagina de consulta de un conjunto de reglas.
 *
 * Los relation managers del recurso se muestran aqui igual que en la edicion,
 * pero Filament los pone en modo lectura al detectar que la pagina es de tipo
 * ViewRecord. Aun asi, RulesRelationManager oculta sus acciones de escritura
 * por su cuenta cuando el conjunto no es borrador: la comprobacion del panel y
 * la del componente son independientes a proposito, porque la primera protege
 * contra un descuido de configuracion y la segunda contra un cambio de estado
 * mientras la pagina esta abierta.
 */
class ViewRuleSet extends ViewRecord
{
    protected static string $resource = RuleSetResource::class;

    public function getTitle(): string
    {
        /** @var RuleSet $record */
        $record = $this->getRecord();

        return "{$record->name} (v{$record->version})";
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * EditAction consulta la policy, que niega update() cuando el
             * conjunto no es borrador. No hace falta duplicar la condicion
             * aqui: si se duplicara, un cambio en la regla de inmutabilidad
             * habria que recordarlo en dos sitios.
             */
            EditAction::make()
                ->label('Editar'),
        ];
    }
}
