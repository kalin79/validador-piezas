<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\BrandAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Mueve piezas y activos de marca del disco 'public' al disco privado.
 *
 * Hasta la auditoria de 2026-09 los archivos se guardaban en 'public' y se
 * servian por /storage sin sesion. Las rutas nuevas ya leen del disco que
 * indica cada registro, asi que los archivos viejos siguen funcionando; este
 * comando los saca del alcance publico.
 *
 * Por defecto solo informa. Con --aplicar copia, verifica el SHA-256 contra
 * el registrado, actualiza storage_disk y recien entonces borra el original.
 *
 *   php artisan archivos:privatizar
 *   php artisan archivos:privatizar --aplicar
 */
class PrivatizarArchivos extends Command
{
    protected $signature = 'archivos:privatizar {--aplicar : Ejecuta el movimiento. Sin esto solo informa.}';

    protected $description = 'Mueve piezas y activos de marca del disco public al disco privado';

    public function handle(): int
    {
        $destino = (string) config('filesystems.piezas_disk', 'local');

        if ($destino === 'public') {
            $this->error("PIEZAS_DISK apunta a 'public'. Configura un disco privado antes de continuar.");

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');
        $movidos = 0;
        $errores = [];

        $grupos = [
            'piezas' => Asset::query()->withoutGlobalScopes()->where('storage_disk', 'public'),
            'activos de marca' => BrandAsset::query()->withoutGlobalScopes()->where('storage_disk', 'public'),
        ];

        foreach ($grupos as $nombre => $consulta) {
            $total = (clone $consulta)->count();
            $this->line("{$nombre}: {$total} en el disco public");

            if (! $aplicar) {
                continue;
            }

            foreach ($consulta->cursor() as $registro) {
                try {
                    $this->mover($registro, $destino);
                    $movidos++;
                } catch (\Throwable $e) {
                    $errores[] = sprintf('%s #%d: %s', $nombre, $registro->id, $e->getMessage());
                }
            }
        }

        if (! $aplicar) {
            $this->warn('Modo informe. Ejecuta con --aplicar para mover los archivos.');

            return self::SUCCESS;
        }

        $this->info("Movidos: {$movidos}");

        foreach ($errores as $error) {
            $this->error($error);
        }

        return $errores === [] ? self::SUCCESS : self::FAILURE;
    }

    private function mover(Asset|BrandAsset $registro, string $destino): void
    {
        $origen = Storage::disk('public');
        $ruta = (string) $registro->storage_path;

        if (! $origen->exists($ruta)) {
            throw new \RuntimeException("no existe {$ruta} en public");
        }

        $stream = $origen->readStream($ruta);
        Storage::disk($destino)->writeStream($ruta, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        // Se verifica la huella antes de tocar la base: si la copia no es
        // identica, el registro sigue apuntando al original intacto.
        $huella = hash('sha256', (string) Storage::disk($destino)->get($ruta));

        if (filled($registro->file_hash) && ! hash_equals((string) $registro->file_hash, $huella)) {
            Storage::disk($destino)->delete($ruta);

            throw new \RuntimeException('la copia no coincide con la huella registrada; no se movio');
        }

        // Por query builder a proposito: solo cambia la ubicacion, no el
        // contenido, y no debe disparar observadores que recalculen nada.
        DB::table($registro->getTable())->where('id', $registro->id)->update(['storage_disk' => $destino]);

        $origen->delete($ruta);
    }
}
