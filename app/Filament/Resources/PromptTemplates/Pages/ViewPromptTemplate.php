<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptTemplates\Pages;

use App\Filament\Resources\PromptTemplates\PromptTemplateResource;
use App\Models\PromptTemplate;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Pagina de consulta de una instruccion del modelo.
 *
 * A diferencia de los conjuntos de reglas, aqui no se define un infolist en el
 * recurso: sin infolist, Filament muestra el formulario con los campos
 * deshabilitados, y para este contenido eso es mejor que cualquier vista que
 * pudiera escribir.
 *
 * La razon es el tipo de dato. Una instruccion son dos bloques de texto de
 * varios miles de caracteres con saltos de linea significativos. Un textarea
 * deshabilitado los conserva tal cual y se desplaza; un campo de texto normal
 * en un infolist colapsa los saltos y convierte el prompt en un parrafo
 * ilegible. El formulario ya resuelve la presentacion, y de paso queda una
 * sola definicion de que campos tiene una instruccion.
 */
class ViewPromptTemplate extends ViewRecord
{
    protected static string $resource = PromptTemplateResource::class;

    public function getTitle(): string
    {
        /** @var PromptTemplate $record */
        $record = $this->getRecord();

        return "{$record->name} (v{$record->version})";
    }

    protected function getHeaderActions(): array
    {
        return [
            // La policy niega update() cuando la plantilla no es borrador, asi
            // que el boton se oculta solo en las publicadas y retiradas.
            EditAction::make()
                ->label('Editar'),
        ];
    }
}
