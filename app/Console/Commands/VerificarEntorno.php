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

        $this->seccion('Configuracion de la aplicacion');
        $this->configuracion();

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

            // Se decide por el driver, no por el texto de la version: MySQL 8
            // responde solo "8.0.33", sin la palabra "mysql", y el control
            // avisaba en falso.
            $esMysql = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);

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

    /**
     * Ajustes que la auditoria de 2026-09 encontro mal puestos o que, mal
     * puestos, rompen la veracidad o la seguridad del sistema.
     */
    private function configuracion(): void
    {
        $produccion = app()->isProduction();

        if (! $produccion) {
            $this->linea('  Entorno '.config('app.env').': debug, cookie HTTPS, cola y correo solo se exigen en produccion.');
        }

        $this->comprobar('APP_DEBUG apagado en produccion', ! ($produccion && config('app.debug')),
            'APP_ENV='.config('app.env').' APP_DEBUG='.(config('app.debug') ? 'true' : 'false'), 'Con debug activo, un error muestra rutas, SQL y variables de entorno a quien lo provoque.', critico: true);

        $this->comprobar('Piezas en disco privado', config('filesystems.piezas_disk') !== 'public',
            'PIEZAS_DISK='.config('filesystems.piezas_disk'), 'En "public" las piezas se sirven sin sesion por /storage.', critico: true);

        $driver = (string) config('ai.driver');
        $this->comprobar('Driver de IA real', ! ($produccion && $driver === 'fake'),
            'AI_DRIVER='.$driver, 'El driver simulado inventa resultados; en produccion el sistema lo rechaza.', critico: true);

        $this->comprobar('Clave de Anthropic presente', $driver !== 'anthropic' || filled(config('ai.anthropic.api_key')),
            '', 'Sin ANTHROPIC_API_KEY las reglas de juicio quedan sin evaluar en todas las piezas.', critico: $produccion);

        $this->comprobar('Cola asincrona', ! ($produccion && config('queue.default') === 'sync'),
            'QUEUE_CONNECTION='.config('queue.default'), 'Con sync, cada validacion corre dentro de la peticion web y puede cortarse por tiempo.');

        $retry = (int) config('queue.connections.'.config('queue.default').'.retry_after', 0);
        $this->comprobar('retry_after mayor que el timeout del job (420 s)', config('queue.default') === 'sync' || $retry > 420,
            'retry_after='.$retry, 'Si es menor, un segundo worker toma la misma validacion y se cobra dos veces.');

        $this->comprobar('Tokens de API con vencimiento', config('sanctum.expiration') !== null,
            '', 'Sin vencimiento, un token filtrado sirve para siempre.');

        $this->comprobar('Cookie de sesion solo por HTTPS', ! $produccion || (bool) config('session.secure'),
            '', 'Sin esto la cookie de sesion puede viajar sin cifrar.');

        $this->comprobar('Idioma espanol', str_starts_with((string) config('app.locale'), 'es'),
            'APP_LOCALE='.config('app.locale'), 'El panel y los mensajes de validacion salen en ingles.');

        $this->comprobar('Logs rotados por dia', ! in_array('single', (array) config('logging.channels.stack.channels'), true),
            'LOG_STACK='.implode(',', (array) config('logging.channels.stack.channels')), 'Con "single" el log crece sin limite.');

        $this->comprobar('Correo real configurado', ! ($produccion && in_array(config('mail.default'), ['log', 'array'], true)),
            'MAIL_MAILER='.config('mail.default'), 'Las alertas de respaldo y avisos por correo no salen del servidor.');

        if (config('backup.enabled')) {
            $this->comprobar('Destinatario de alertas de respaldo', filled(config('backup.notifications.mail.to')),
                '', 'Define BACKUP_NOTIFY_EMAIL: si el respaldo falla, nadie se entera.');

            $this->comprobar('Respaldo fuera del servidor', in_array('respaldos', (array) config('backup.backup.destination.disks'), true),
                '', 'BACKUP_DISK=respaldos. Un respaldo en el mismo servidor se pierde con el mismo incidente.');
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
