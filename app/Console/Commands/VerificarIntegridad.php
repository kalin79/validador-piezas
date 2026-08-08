<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\BrandAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Comprueba que cada archivo en disco sigue siendo el que se valido.
 *
 * Un respaldo que copia archivos corruptos guarda la corrupcion. Y en este
 * sistema el archivo es la evidencia: si la pieza cambio despues de la
 * validacion, el veredicto del historial deja de referirse a lo que hay.
 *
 * Se apoya en file_hash, que ya se calcula al subir. Aqui solo se recalcula y
 * se compara.
 *
 *   php artisan integridad:verificar
 *   php artisan integridad:verificar --desde=2026-08-01
 */
class VerificarIntegridad extends Command
{
    protected $signature = 'integridad:verificar
                            {--desde= : Solo piezas creadas desde esta fecha (Y-m-d).}
                            {--limite=0 : Maximo de piezas a revisar. 0 = todas.}';

    protected $description = 'Verifica que los archivos en disco coincidan con su huella registrada';

    public function handle(): int
    {
        $intactos = 0;
        $alterados = [];
        $ausentes = [];

        $this->line('Piezas');
        $this->line(str_repeat('-', 60));

        $consulta = Asset::query()->withoutGlobalScopes()->orderBy('id');

        if ($desde = $this->option('desde')) {
            $consulta->whereDate('created_at', '>=', $desde);
        }

        if (($limite = (int) $this->option('limite')) > 0) {
            $consulta->limit($limite);
        }

        $consulta->chunk(100, function ($piezas) use (&$intactos, &$alterados, &$ausentes): void {
            foreach ($piezas as $pieza) {
                $disco = Storage::disk($pieza->storage_disk);

                if (! $disco->exists($pieza->storage_path)) {
                    $ausentes[] = "#{$pieza->id} {$pieza->original_filename}";

                    continue;
                }

                $actual = hash_file('sha256', $disco->path($pieza->storage_path));

                if ($actual === $pieza->file_hash) {
                    $intactos++;

                    continue;
                }

                $alterados[] = "#{$pieza->id} {$pieza->original_filename}";
            }
        });

        $this->line("  intactos:  {$intactos}");
        $this->line('  alterados: '.count($alterados));
        $this->line('  ausentes:  '.count($ausentes));

        foreach (array_slice($alterados, 0, 20) as $a) {
            $this->error('  ALTERADO  '.$a);
        }

        foreach (array_slice($ausentes, 0, 20) as $a) {
            $this->error('  AUSENTE   '.$a);
        }

        // Los activos de marca no se validan contra hash al usarse, pero si
        // cambian sin que nadie lo note, las validaciones futuras se comparan
        // contra un logo distinto del que aprobo el cliente.
        $this->newLine();
        $this->line('Activos de marca');
        $this->line(str_repeat('-', 60));

        $activosOk = 0;
        $activosMal = [];

        foreach (BrandAsset::query()->withoutGlobalScopes()->get() as $activo) {
            $disco = Storage::disk($activo->storage_disk);

            if (! $disco->exists($activo->storage_path)) {
                $activosMal[] = "#{$activo->id} {$activo->name} (ausente)";

                continue;
            }

            hash_file('sha256', $disco->path($activo->storage_path)) === $activo->file_hash
                ? $activosOk++
                : $activosMal[] = "#{$activo->id} {$activo->name} (alterado)";
        }

        $this->line("  intactos: {$activosOk}");
        $this->line('  con problema: '.count($activosMal));

        foreach ($activosMal as $a) {
            $this->error('  '.$a);
        }

        $hayProblemas = $alterados !== [] || $ausentes !== [] || $activosMal !== [];

        $this->newLine();

        if ($hayProblemas) {
            $this->error('Hay archivos que no coinciden con su huella. Revisa antes del proximo respaldo.');

            return self::FAILURE;
        }

        $this->info('Todo intacto.');

        return self::SUCCESS;
    }
}
