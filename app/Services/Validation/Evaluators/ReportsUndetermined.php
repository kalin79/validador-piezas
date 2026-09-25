<?php

declare(strict_types=1);

namespace App\Services\Validation\Evaluators;

use App\Models\Asset;
use App\Models\Rule;
use Illuminate\Support\Collection;

/**
 * Un evaluador que puede declarar que reglas no pudo medir.
 *
 * Sin esto, un evaluador que retorna [] por falta de datos (sin canal, sin
 * paleta extraida, sin colores autorizados) es indistinguible de uno que midio
 * y no encontro problemas, y la regla termina reportada como cumplida.
 */
interface ReportsUndetermined
{
    /**
     * @param  Collection<int, Rule>  $rules  reglas que este evaluador atiende
     * @return array<string, string>  codigo de regla => motivo por el que no se pudo determinar
     */
    public function undetermined(Asset $asset, Collection $rules, ?string $channel): array;
}
