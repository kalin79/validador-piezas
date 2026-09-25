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
                            {--limite=0 : Maximo de piezas a revisar. 0 = todas.}
                            {--todos : Lista todos los problemas, no solo los primeros 20.}
                            {--avisar : Notifica en el panel a los super_admin si hay problemas nuevos.}';

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

        $this->listar($alterados, 'ALTERADO ');
        $this->listar($ausentes, 'AUSENTE  ');

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
            // Alterado y ausente son problemas distintos: uno es evidencia
            // que cambio, el otro evidencia que ya no existe.
            if ($alterados !== []) {
                $this->error(count($alterados).' pieza(s) no coinciden con su huella: el archivo cambio despues de validarse.');
            }

            if ($ausentes !== []) {
                $this->error(count($ausentes).' pieza(s) ya no existen en disco: su veredicto queda sin evidencia.');
            }

            if ($activosMal !== []) {
                $this->error(count($activosMal).' activo(s) de marca ausentes o alterados.');
            }

            if ($this->option('avisar')) {
                $this->avisar($alterados, $ausentes, $activosMal);
            }

            return self::FAILURE;
        }

        $this->info('Todo intacto.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $items
     */
    private function listar(array $items, string $etiqueta): void
    {
        $mostrar = $this->option('todos') ? $items : array_slice($items, 0, 20);

        foreach ($mostrar as $item) {
            $this->error('  '.$etiqueta.' '.$item);
        }

        // Antes se cortaba en 20 sin decirlo y el total no cuadraba con la lista.
        if (count($items) > count($mostrar)) {
            $this->warn(sprintf('  ... y %d mas. Usa --todos para verlos.', count($items) - count($mostrar)));
        }
    }

    /**
     * Notifica solo cuando el conjunto de problemas cambia respecto de la
     * corrida anterior. Repetir cada dia el mismo aviso hace que se ignore.
     *
     * @param  array<int, string>  $alterados
     * @param  array<int, string>  $ausentes
     * @param  array<int, string>  $activosMal
     */
    private function avisar(array $alterados, array $ausentes, array $activosMal): void
    {
        $huella = hash('sha256', json_encode([$alterados, $ausentes, $activosMal]));

        if (cache()->get('integridad.ultima_huella') === $huella) {
            $this->line('Mismos problemas que la corrida anterior: no se notifica de nuevo.');

            return;
        }

        cache()->forever('integridad.ultima_huella', $huella);

        $destinatarios = \App\Models\User::role('super_admin')->where('is_active', true)->get();

        \Filament\Notifications\Notification::make()
            ->title('Integridad de archivos: hay problemas')
            ->body(sprintf(
                '%d alterada(s), %d ausente(s), %d activo(s) de marca con problema. Detalle: php artisan integridad:verificar --todos',
                count($alterados),
                count($ausentes),
                count($activosMal),
            ))
            ->danger()
            ->sendToDatabase($destinatarios);

        $this->line('Aviso enviado a '.$destinatarios->count().' super_admin.');
    }
}
