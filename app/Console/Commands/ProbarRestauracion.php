<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Restaura el ultimo respaldo en una base temporal y comprueba que sirve.
 *
 * Un respaldo configurado no es un respaldo. Lo unico que demuestra que
 * funciona es restaurarlo, y la mayoria de los equipos descubre que el suyo
 * estaba vacio, truncado o cifrado con una clave perdida el dia que lo
 * necesitan.
 *
 * Este comando cierra ese hueco: descomprime el archivo, lo carga en una base
 * desechable, cuenta los registros de las tablas que sostienen la auditoria y
 * los compara con produccion. Al terminar borra la base temporal.
 *
 * No toca la base real en ningun momento.
 *
 *   php artisan respaldo:probar
 *   php artisan respaldo:probar --archivo=/ruta/al/respaldo.zip
 *   php artisan respaldo:probar --conservar   (deja la base temporal para inspeccionarla)
 */
class ProbarRestauracion extends Command
{
    protected $signature = 'respaldo:probar
                            {--archivo= : Ruta a un zip concreto. Por defecto, el mas reciente del disco local.}
                            {--conservar : No borrar la base temporal al terminar.}';

    protected $description = 'Restaura el ultimo respaldo en una base temporal y verifica su contenido';

    /**
     * Tablas cuya perdida no se puede reconstruir de ninguna otra fuente.
     *
     * Se comparan contra produccion con una tolerancia: entre el momento del
     * respaldo y ahora pueden haberse creado registros nuevos, y eso es normal.
     * Lo que no es normal es que falten.
     */
    private const TABLAS_CRITICAS = [
        'clients',
        'brands',
        'rule_sets',
        'rules',
        'palettes',
        'palette_colors',
        'assets',
        'validation_runs',
        'findings',
        'verdicts',
        'human_reviews',
        'audit_logs',
    ];

    public function handle(): int
    {
        $zip = $this->resolverArchivo();

        if ($zip === null) {
            $this->error('No se encontro ningun respaldo. Corre primero: php artisan backup:run');

            return self::FAILURE;
        }

        $this->info('Respaldo: '.$zip);
        $this->line('Peso:     '.$this->humano(filesize($zip)));
        $this->line('Fecha:    '.date('Y-m-d H:i:s', filemtime($zip)));
        $this->newLine();

        $trabajo = storage_path('app/backup-temp/prueba-'.getmypid());
        $baseTemporal = 'restauracion_prueba_'.getmypid();

        try {
            $dump = $this->extraerDump($zip, $trabajo);

            $this->line('Dump encontrado: '.basename($dump).'  ('.$this->humano(filesize($dump)).')');

            if (filesize($dump) < 1024) {
                $this->error('El dump pesa menos de 1 KB. El respaldo esta vacio.');

                return self::FAILURE;
            }

            $this->crearBase($baseTemporal);
            $this->cargarDump($baseTemporal, $dump);

            $resultado = $this->comparar($baseTemporal);

            return $resultado ? self::SUCCESS : self::FAILURE;
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if (! $this->option('conservar')) {
                $this->borrarBase($baseTemporal);
                $this->borrarDirectorio($trabajo);
            } else {
                $this->newLine();
                $this->warn('Base temporal conservada: '.$baseTemporal);
                $this->warn('Borrala con: DROP DATABASE `'.$baseTemporal.'`;');
            }
        }
    }

    private function resolverArchivo(): ?string
    {
        $indicado = $this->option('archivo');

        if ($indicado !== null) {
            return is_file($indicado) ? $indicado : null;
        }

        $carpeta = config('backup.backup.name');
        $disco = Storage::disk('local');

        $candidatos = collect($disco->files($carpeta))
            ->filter(fn (string $f): bool => str_ends_with($f, '.zip'))
            ->sortByDesc(fn (string $f): int => $disco->lastModified($f));

        $mas = $candidatos->first();

        return $mas === null ? null : $disco->path($mas);
    }

    private function extraerDump(string $zip, string $destino): string
    {
        if (! is_dir($destino) && ! mkdir($destino, 0700, true) && ! is_dir($destino)) {
            throw new RuntimeException('No se pudo crear el directorio de trabajo: '.$destino);
        }

        $archivo = new ZipArchive();
        $clave = config('backup.backup.password');

        if ($archivo->open($zip) !== true) {
            throw new RuntimeException('No se pudo abrir el zip. Puede estar corrupto.');
        }

        if (filled($clave)) {
            $archivo->setPassword((string) $clave);
        }

        $sql = null;

        for ($i = 0; $i < $archivo->numFiles; $i++) {
            $nombre = $archivo->getNameIndex($i);

            if ($nombre !== false && str_ends_with($nombre, '.sql')) {
                $sql = $nombre;
                break;
            }
        }

        if ($sql === null) {
            $archivo->close();

            throw new RuntimeException(
                'El zip no contiene ningun .sql. El respaldo no incluye la base de datos.'
            );
        }

        if (! $archivo->extractTo($destino, [$sql])) {
            $archivo->close();

            throw new RuntimeException(
                'No se pudo extraer el dump. Si el archivo esta cifrado, revisa BACKUP_ARCHIVE_PASSWORD.'
            );
        }

        $archivo->close();

        return $destino.DIRECTORY_SEPARATOR.$sql;
    }

    private function crearBase(string $nombre): void
    {
        $this->line('Creando base temporal: '.$nombre);

        DB::statement("CREATE DATABASE `{$nombre}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    private function cargarDump(string $base, string $dump): void
    {
        $this->line('Cargando el dump...');

        $proceso = Process::fromShellCommandline(
            sprintf(
                'mysql --host=%s --port=%s --user=%s --password=%s %s < %s',
                escapeshellarg((string) config('database.connections.mysql.host')),
                escapeshellarg((string) config('database.connections.mysql.port')),
                escapeshellarg((string) config('database.connections.mysql.username')),
                escapeshellarg((string) config('database.connections.mysql.password')),
                escapeshellarg($base),
                escapeshellarg($dump),
            )
        );

        $proceso->setTimeout(600);
        $proceso->run();

        if (! $proceso->isSuccessful()) {
            throw new RuntimeException('Fallo la carga del dump: '.$proceso->getErrorOutput());
        }
    }

    private function comparar(string $base): bool
    {
        $this->newLine();
        $this->line(sprintf('%-20s %12s %12s   %s', 'tabla', 'respaldo', 'produccion', 'estado'));
        $this->line(str_repeat('-', 62));

        $todoBien = true;
        $faltantes = [];

        foreach (self::TABLAS_CRITICAS as $tabla) {
            try {
                $enRespaldo = (int) DB::selectOne("SELECT COUNT(*) AS n FROM `{$base}`.`{$tabla}`")->n;
            } catch (\Throwable $e) {
                $this->line(sprintf('%-20s %12s %12s   %s', $tabla, '-', '-', 'NO EXISTE en el respaldo'));
                $faltantes[] = $tabla;
                $todoBien = false;

                continue;
            }

            $enProduccion = (int) DB::table($tabla)->count();

            // El respaldo es de antes: tener menos registros es esperado. Tener
            // MENOS de los que habia al momento del respaldo no se puede saber,
            // pero cero cuando produccion tiene datos si es una senal clara.
            $estado = match (true) {
                $enRespaldo === 0 && $enProduccion > 0 => 'VACIA',
                $enRespaldo > $enProduccion => 'ok (respaldo mas viejo)',
                default => 'ok',
            };

            if ($estado === 'VACIA') {
                $todoBien = false;
            }

            $this->line(sprintf('%-20s %12d %12d   %s', $tabla, $enRespaldo, $enProduccion, $estado));
        }

        $this->newLine();

        if ($faltantes !== []) {
            $this->error('Tablas ausentes en el respaldo: '.implode(', ', $faltantes));
        }

        if ($todoBien) {
            $this->info('Restauracion verificada. El respaldo sirve.');
        } else {
            $this->error('El respaldo NO es confiable. Revisa la configuracion antes de necesitarlo.');
        }

        return $todoBien;
    }

    private function borrarBase(string $nombre): void
    {
        try {
            DB::statement("DROP DATABASE IF EXISTS `{$nombre}`");
        } catch (\Throwable $e) {
            $this->warn('No se pudo borrar la base temporal '.$nombre.': '.$e->getMessage());
        }
    }

    private function borrarDirectorio(string $ruta): void
    {
        if (! is_dir($ruta)) {
            return;
        }

        foreach (glob($ruta.'/*') ?: [] as $archivo) {
            is_dir($archivo) ? $this->borrarDirectorio($archivo) : @unlink($archivo);
        }

        @rmdir($ruta);
    }

    private function humano(int|false $bytes): string
    {
        if ($bytes === false) {
            return '?';
        }

        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }

        return round($bytes / 1024).' KB';
    }
}
