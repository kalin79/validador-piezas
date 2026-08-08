<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Asset;
use App\Services\ValidationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Se despacha como Job aunque el driver sea 'sync'.
 *
 * Con sync se ejecuta en el mismo proceso y no hace falta worker, pero el dia
 * que se active una cola real no hay que reescribir nada: solo cambia una
 * variable de entorno.
 */
class RunValidation implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public Asset $asset,
        public ?int $triggeredBy = null,
        public ?string $model = null,
    ) {}

    public function handle(ValidationRunner $runner): void
    {
        $runner->run($this->asset, $this->triggeredBy, model: $this->model);
    }
}
