<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Comprueba si el servidor puede correr este sistema.
 *
 * Pensado para ejecutarse en el hosting antes de dar por buena la instalacion,
 * o incluso durante el periodo de prueba del plan. Cada comprobacion dice que
 * se rompe si falla, no solo que falta.
 *
 *   php artisan entorno:verificar
 */
class VerificarEntorno extends Command
{
    protected $signature = 'entorno:verificar';

    protected $description = 'Comprueba que el servidor cumple lo que el sistema necesita';

    private int $criticos = 0;

    private int $advertencias = 0;

    public function handle(): int
    {
        $this->seccion('PHP');
        $this->php();

        $this->seccion('Extensiones');
        $this->extensiones();

        $this->seccion('Funciones del sistema');
        $this->funciones();

        $this->seccion('Limites de ejecucion');
        $this->limites();

        $this->seccion('Escritura en disco');
        $this->escritura();

        $this->seccion('Base de datos');
        $this->baseDeDatos();

        $this->seccion('Salida a internet');
        $this->red();

        $this->seccion('RESUMEN');

        $this->line('  criticos:    '.$this->criticos);
        $this->line('  advertencias: '.$this->advertencias);
        $this->newLine();

        if ($this->criticos > 0) {
            $this->error('Este servidor NO puede correr el sistema tal como esta. Revisa los [CRITICO].');

            return self::FAILURE;
        }

        if ($this->advertencias > 0) {
            $this->warn('Funciona, con las limitaciones marcadas como [AVISO].');

            return self::SUCCESS;
        }

        $this->info('El servidor cumple todo.');

        return self::SUCCESS;
    }

    private function php(): void
    {
        $version = PHP_VERSION;

        $this->comprobar(
            'PHP 8.3 o superior',
            version_compare($version, '8.3.0', '>='),
            'version actual: '.$version,
            'El proyecto declara PHP ^8.3. Con una version menor, composer install falla.',
            critico: true,
        );

        $this->linea('  version de CLI: '.$version);
        $this->linea('  Ojo: la version de la linea de comandos y la del navegador pueden diferir en cPanel.');
    }

    private function extensiones(): void
    {
        $necesarias = [
            'gd' => 'Extraccion de la paleta de colores y redimensionado de imagenes.',
            'zip' => 'Respaldos y prueba de restauracion.',
            'pdo_mysql' => 'Conexion a la base de datos.',
            'mbstring' => 'Manejo de texto con tildes.',
            'fileinfo' => 'Deteccion del tipo real de los archivos subidos.',
            'curl' => 'Llamadas a la API del modelo.',
            'openssl' => 'HTTPS y cifrado de respaldos.',
        ];

        foreach ($necesarias as $ext => $paraQue) {
            $this->comprobar(
                'extension '.$ext,
                extension_loaded($ext),
                '',
                $paraQue,
                critico: true,
            );
        }

        if (! extension_loaded('gd') && extension_loaded('imagick')) {
            $this->linea('  Hay imagick pero no gd. El extractor de paleta usa gd: habria que adaptarlo.');
        }
    }

    private function funciones(): void
    {
        $deshabilitadas = array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));

        $this->comprobar(
            'proc_open disponible',
            function_exists('proc_open') && ! in_array('proc_open', $deshabilitadas, true),
            '',
            'Sin proc_open no funcionan los respaldos (mysqldump) ni respaldo:probar. '
                .'Muchos hosting compartidos la deshabilitan por seguridad.',
            critico: false,
        );

        $this->comprobar(
            'symlink disponible',
            function_exists('symlink') && ! in_array('symlink', $deshabilitadas, true),
            '',
            'php artisan storage:link no funciona. Se puede resolver creando el enlace por FTP o cPanel.',
            critico: false,
        );

        if ($deshabilitadas !== []) {
            $this->linea('  funciones deshabilitadas: '.implode(', ', $deshabilitadas));
        }

        // mysqldump es lo que usa spatie/laravel-backup para el dump.
        if (function_exists('exec')) {
            @exec('which mysqldump 2>/dev/null', $salida, $codigo);

            $this->comprobar(
                'mysqldump en el PATH',
                $codigo === 0 && $salida !== [],
                $salida[0] ?? '',
                'Sin mysqldump no hay respaldo de base de datos. Se puede indicar su ruta '
                    .'en config/database.php con la clave dump.dump_binary_path.',
                critico: false,
            );
        }
    }

    private function limites(): void
    {
        $tiempo = (int) ini_get('max_execution_time');

        /*
         * El limite mas propenso a morder.
         *
         * Una validacion con IA tarda entre 18 y 25 segundos: el modelo lee la
         * imagen y evalua treinta reglas. Con QUEUE_CONNECTION=sync eso ocurre
         * dentro de la peticion HTTP.
         *
         * Y no basta con el limite de PHP: muchos hosting compartidos ponen un
         * proxy delante con su propio corte a los 30 o 60 segundos, que no se
         * puede cambiar desde la aplicacion.
         */
        $this->comprobar(
            'max_execution_time suficiente',
            $tiempo === 0 || $tiempo >= 60,
            'actual: '.($tiempo === 0 ? 'sin limite' : $tiempo.'s'),
            'Una validacion con IA tarda 18-25 s. Con menos de 60 s se corta a mitad. '
                .'Mitigacion: QUEUE_CONNECTION=database y un worker por cron.',
            critico: false,
        );

        $memoria = $this->aBytes((string) ini_get('memory_limit'));

        $this->comprobar(
            'memory_limit de al menos 256M',
            $memoria === -1 || $memoria >= 268435456,
            'actual: '.ini_get('memory_limit'),
            'Procesar una imagen grande con gd consume bastante. Con 128M una pieza '
                .'de 4000 px agota la memoria.',
            critico: false,
        );

        $subida = $this->aBytes((string) ini_get('upload_max_filesize'));
        $post = $this->aBytes((string) ini_get('post_max_size'));

        $this->comprobar(
            'upload_max_filesize de al menos 20M',
            $subida >= 20971520,
            'actual: '.ini_get('upload_max_filesize'),
            'El formulario admite piezas de hasta 20 MB. Con menos, la subida falla sin mensaje claro.',
            critico: false,
        );

        $this->comprobar(
            'post_max_size mayor que upload_max_filesize',
            $post >= $subida,
            'actual: '.ini_get('post_max_size'),
            'Si post_max_size es menor, la subida se pierde en silencio.',
            critico: false,
        );
    }

    private function escritura(): void
    {
        $rutas = [
            storage_path('app'),
            storage_path('framework'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        foreach ($rutas as $ruta) {
            $this->comprobar(
                'escritura en '.str_replace(base_path().'/', '', $ruta),
                is_writable($ruta),
                '',
                'Sin permiso de escritura la aplicacion no arranca.',
                critico: true,
            );
        }
    }

    private function baseDeDatos(): void
    {
        try {
            $version = DB::selectOne('SELECT VERSION() AS v')->v ?? '?';

            $this->comprobar('conexion a la base', true, $version);

            $esMysql = str_contains(strtolower((string) $version), 'mysql')
                || str_contains(strtolower((string) $version), 'mariadb');

            $this->comprobar(
                'motor MySQL o MariaDB',
                $esMysql,
                '',
                'El proyecto usa columnas json y sentencias afinadas para MySQL.',
                critico: false,
            );
        } catch (Throwable $e) {
            $this->comprobar('conexion a la base', false, '', $e->getMessage(), critico: true);
        }
    }

    private function red(): void
    {
        try {
            /*
             * Se comprueba que el servidor pueda salir a la API. Algunos
             * hosting compartidos bloquean las conexiones salientes a puertos
             * o dominios no listados, y el sintoma seria que todas las
             * validaciones fallan con un timeout que parece de la API.
             *
             * No se envia la clave: solo interesa que la conexion se
             * establezca. Un 401 tambien confirma que hay salida.
             */
            $respuesta = Http::timeout(15)
                ->withHeaders(['anthropic-version' => '2023-06-01'])
                ->post('https://api.anthropic.com/v1/messages', []);

            $this->comprobar(
                'salida HTTPS a api.anthropic.com',
                true,
                'respondio '.$respuesta->status().' (cualquier respuesta confirma la conexion)',
            );
        } catch (Throwable $e) {
            $this->comprobar(
                'salida HTTPS a api.anthropic.com',
                false,
                '',
                'Sin salida a internet las validaciones con IA no funcionan. '
                    .'Detalle: '.mb_substr($e->getMessage(), 0, 120),
                critico: true,
            );
        }
    }

    private function comprobar(
        string $titulo,
        bool $cumple,
        string $detalle = '',
        string $consecuencia = '',
        bool $critico = false,
    ): void {
        if ($cumple) {
            $this->line('  <fg=green>[ OK ]</>    '.$titulo.($detalle !== '' ? '  ·  '.$detalle : ''));

            return;
        }

        $critico ? $this->criticos++ : $this->advertencias++;

        $etiqueta = $critico ? '<fg=red>[CRITICO]</>' : '<fg=yellow>[AVISO]</>';

        $this->line('  '.$etiqueta.' '.$titulo.($detalle !== '' ? '  ·  '.$detalle : ''));

        if ($consecuencia !== '') {
            $this->line('            '.$consecuencia);
        }
    }

    private function linea(string $t): void
    {
        $this->line('<fg=gray>'.$t.'</>');
    }

    private function seccion(string $t): void
    {
        $this->newLine();
        $this->line('<options=bold>'.$t.'</>');
        $this->line(str_repeat('-', 68));
    }

    private function aBytes(string $valor): int
    {
        $valor = trim($valor);

        if ($valor === '-1') {
            return -1;
        }

        $unidad = strtolower($valor[strlen($valor) - 1] ?? '');
        $numero = (int) $valor;

        return match ($unidad) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => $numero,
        };
    }
}
