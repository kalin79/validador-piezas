<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ValidationStatus;
use App\Models\Asset;
use App\Models\User;
use App\Models\ValidationRun;
use App\Services\ValidationRunner;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Valida una pieza en segundo plano.
 *
 * Reglas para que una cola real no duplique trabajo ni costo:
 *
 * - Un solo intento. Los errores transitorios de la API ya se reintentan
 *   dentro del proveedor, ANTES de que exista la ejecucion. Reintentar el job
 *   completo creaba una ejecucion nueva (y un cobro nuevo) por intento.
 * - Unico por pieza mientras corre: un doble clic en "Revalidar" no lanza dos.
 * - El timeout del job es mayor que el peor caso del proveedor, y el
 *   retry_after de la cola es mayor que el timeout (config/queue.php). Si no,
 *   el worker B toma el mismo job mientras A sigue esperando a Claude.
 * - failed() cierra la ejecucion que quedo colgada (timeout, worker muerto) y
 *   avisa a quien la lanzo.
 */
class RunValidation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 420;

    public int $uniqueFor = 900;

    public bool $failOnTimeout = true;

    public function __construct(
        public Asset $asset,
        public ?int $triggeredBy = null,
        public ?string $model = null,
    ) {}

    public function uniqueId(): string
    {
        return 'asset-'.$this->asset->getKey();
    }

    public function handle(ValidationRunner $runner): void
    {
        $run = $runner->run($this->asset, $this->triggeredBy, model: $this->model);

        $this->avisar($run);
    }

    public function failed(?Throwable $e): void
    {
        // El runner marca como fallida su propia ejecucion cuando lanza una
        // excepcion. Esto cubre lo que el runner no alcanza a cerrar: un
        // timeout o un worker que murio a mitad de camino.
        ValidationRun::query()
            ->where('asset_id', $this->asset->getKey())
            ->where('status', ValidationStatus::Running->value)
            ->update([
                'status' => ValidationStatus::Failed->value,
                'finished_at' => now(),
                'error_message' => 'El proceso de validacion no termino: '.mb_substr((string) $e?->getMessage(), 0, 300),
                'updated_at' => now(),
            ]);

        $usuario = $this->triggeredBy !== null ? User::query()->find($this->triggeredBy) : null;

        if ($usuario !== null) {
            Notification::make()
                ->title('La validacion no se completo')
                ->body("{$this->asset->original_filename}: no hay veredicto. Puedes revalidarla.")
                ->danger()
                ->sendToDatabase($usuario);
        }
    }

    private function avisar(ValidationRun $run): void
    {
        // Con QUEUE_CONNECTION=sync quien lanzo ya ve el resultado en pantalla.
        if (config('queue.default') === 'sync' || $this->triggeredBy === null) {
            return;
        }

        $usuario = User::query()->find($this->triggeredBy);

        if ($usuario === null) {
            return;
        }

        $estado = $run->verdict?->status;

        Notification::make()
            ->title('Validacion terminada: '.($estado?->label() ?? 'sin veredicto'))
            ->body($this->asset->original_filename)
            ->color($estado?->color() ?? 'gray')
            ->sendToDatabase($usuario);
    }
}
