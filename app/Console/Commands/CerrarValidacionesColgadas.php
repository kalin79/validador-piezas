<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ValidationStatus;
use App\Models\ValidationRun;
use Illuminate\Console\Command;

/**
 * Cierra las ejecuciones que quedaron en "pendiente" o "en curso".
 *
 * Pasa cuando el proceso muere a mitad de camino (timeout del servidor web,
 * worker reiniciado, despliegue). Sin esto la pieza queda "validandose" para
 * siempre y nadie sabe que tiene que revalidarla. Una ejecucion cerrada como
 * fallida no tiene veredicto, asi que nunca cuenta como aprobacion.
 *
 *   php artisan validaciones:cerrar-colgadas
 *   php artisan validaciones:cerrar-colgadas --minutos=30
 */
class CerrarValidacionesColgadas extends Command
{
    protected $signature = 'validaciones:cerrar-colgadas {--minutos=15 : Antiguedad minima para considerarla colgada.}';

    protected $description = 'Marca como fallidas las validaciones que no terminaron';

    public function handle(): int
    {
        $minutos = max(5, (int) $this->option('minutos'));
        $limite = now()->subMinutes($minutos);

        $cerradas = ValidationRun::query()
            ->whereIn('status', [ValidationStatus::Pending->value, ValidationStatus::Running->value])
            ->where(fn ($q) => $q->where('started_at', '<', $limite)
                ->orWhere(fn ($s) => $s->whereNull('started_at')->where('created_at', '<', $limite)))
            ->update([
                'status' => ValidationStatus::Failed->value,
                'finished_at' => now(),
                'error_message' => "La validacion no termino en {$minutos} minutos y se cerro automaticamente. Revalida la pieza.",
                'updated_at' => now(),
            ]);

        $this->info("Ejecuciones cerradas: {$cerradas}");

        return self::SUCCESS;
    }
}
