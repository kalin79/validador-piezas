<?php

declare(strict_types=1);

namespace App\Filament\Resources\RuleSets\Pages;

use App\Enums\RuleSetOwnerType;
use App\Enums\RuleSetStatus;
use App\Filament\Resources\RuleSets\RuleSetResource;
use App\Models\RuleSet;
use Filament\Resources\Pages\CreateRecord;

class CreateRuleSet extends CreateRecord
{
    protected static string $resource = RuleSetResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $nivel = $data['owner_type'] ?? RuleSetOwnerType::Client->value;

        // En un conjunto de nivel Cliente, el dueno ES el cliente. El campo
        // "Marca" esta oculto en ese caso, y Filament no envia los campos
        // ocultos: owner_id llegaba vacio y la columna es obligatoria.
        //
        // Se resuelve aqui y no en el formulario porque un campo oculto no
        // deshidrata aunque se le asigne valor: la garantia tiene que estar
        // del lado del servidor.
        if ($nivel === RuleSetOwnerType::Client->value) {
            $data['owner_id'] = $data['client_id'];
        }

        // La version se calcula sobre el dueno real, no sobre lo que haya
        // quedado en el formulario. El indice unico es (owner_type, owner_id,
        // version) e incluye los borrados logicos, asi que se cuentan tambien.
        $data['version'] = (int) RuleSet::withTrashed()
            ->where('owner_type', $nivel)
            ->where('owner_id', $data['owner_id'])
            ->max('version') + 1;

        // Un conjunto nace en borrador siempre. Publicar es una accion
        // aparte, con confirmacion, que ademas retira la version anterior.
        $data['status'] = RuleSetStatus::Draft->value;
        $data['created_by'] = auth()->id();

        // Segunda linea: el dueno elegido tiene que estar al alcance de quien
        // crea, verificado con la misma politica que rige la edicion. Las
        // opciones del formulario ya lo acotan; esto cubre la peticion
        // manipulada.
        abort_unless(auth()->user()?->can('update', new RuleSet($data)) === true, 403);

        return $data;
    }
}
