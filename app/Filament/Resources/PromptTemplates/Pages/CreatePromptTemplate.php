<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptTemplates\Pages;

use App\Enums\RuleSetStatus;
use App\Filament\Resources\PromptTemplates\PromptTemplateResource;
use App\Models\PromptTemplate;
use Filament\Resources\Pages\CreateRecord;

class CreatePromptTemplate extends CreateRecord
{
    protected static string $resource = PromptTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Una marca implica su cliente. Si el formulario dejo el cliente vacio
        // pero eligio marca, se completa: sin eso, el alcance quedaria
        // inconsistente y la cascada no encontraria la instruccion.
        if (filled($data['brand_id'] ?? null) && blank($data['client_id'] ?? null)) {
            $data['client_id'] = \App\Models\Brand::query()
                ->whereKey($data['brand_id'])
                ->value('client_id');
        }

        $data['version'] = PromptTemplate::siguienteVersion(
            $data['key'] ?? 'piece_validation',
            $data['client_id'] ?? null,
            $data['brand_id'] ?? null,
        );

        // El formulario no expone el esquema de salida, asi que una plantilla
        // creada desde el panel nacia con output_schema en nulo. AiEvaluator
        // entonces le pasaba al proveedor ['type' => 'object'] a secas: sin
        // propiedades ni campos requeridos, el modelo respondia en formato
        // libre y omitia bloques que el sistema esperaba.
        //
        // Se hereda el esquema de la plantilla vigente de la misma clave. Es
        // el mismo contrato de salida para todos los alcances; lo que cambia
        // entre plantillas son las instrucciones, no la forma de la respuesta.
        if (blank($data['output_schema'] ?? null)) {
            $data['output_schema'] = PromptTemplate::query()
                ->where('key', $data['key'] ?? 'piece_validation')
                ->whereNotNull('output_schema')
                ->orderByDesc('version')
                ->value('output_schema');
        }

        // Nace en borrador siempre. Publicar es una accion aparte que ademas
        // retira la version anterior del mismo alcance.
        $data['status'] = RuleSetStatus::Draft->value;

        // El alcance elegido tiene que estar al alcance de quien crea. Ver
        // PromptTemplatePolicy::update.
        abort_unless(auth()->user()?->can('update', new PromptTemplate($data)) === true, 403);

        return $data;
    }
}
