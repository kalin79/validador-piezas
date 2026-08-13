<?php

declare(strict_types=1);

/**
 * Por que el uploader de Activos de marca se queda en "Waiting for size".
 *
 *   php diagnostico-archivo.php
 *
 * FilePond, el componente que usa Filament para subir archivos, hace algo que
 * la tabla no hace: al abrir la pantalla de edicion pide el archivo que ya
 * estaba guardado con fetch() para saber cuanto pesa. Una etiqueta <img> se
 * salta las reglas de origen del navegador; un fetch() no. Por eso la
 * miniatura del listado se ve y el uploader se queda girando: son dos formas
 * distintas de pedir el mismo archivo.
 *
 * Este script mira el lado del servidor —la URL que se genera, el enlace de
 * storage, el archivo en disco— y deja claro cual de las causas posibles es.
 *
 * Solo lee. No escribe nada.
 */

$raiz = __DIR__;

if (! is_file($raiz.'/vendor/autoload.php') || ! is_file($raiz.'/bootstrap/app.php')) {
    echo "Corre esto desde la raiz del proyecto Laravel.\n";

    exit(1);
}

require $raiz.'/vendor/autoload.php';

$app = require $raiz.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function linea(): void
{
    echo str_repeat('-', 72)."\n";
}

$problemas = [];

/* ------------------------------------------------------------------ */
echo "\nAPP_URL Y LA URL QUE SE GENERA\n";
linea();

$appUrl = (string) config('app.url');
echo "  APP_URL                {$appUrl}\n";

if (str_starts_with($appUrl, 'http://')) {
    $problemas[] = 'APP_URL usa http. Si entras al panel por https, el navegador '
        ."bloquea el fetch del archivo por contenido mixto: la imagen se ve (las\n"
        .'    etiquetas <img> se autocorrigen a https) pero el uploader se cuelga.';
}

if ($appUrl === 'http://localhost' || $appUrl === '') {
    $problemas[] = 'APP_URL quedo en el valor de ejemplo. Tiene que ser el dominio '
        .'exacto por el que entras al panel.';
}

if (str_ends_with($appUrl, '/')) {
    $problemas[] = 'APP_URL termina en barra: se generan URLs con doble barra.';
}

/* ------------------------------------------------------------------ */
echo "\nEL ENLACE public/storage\n";
linea();

$enlace = $raiz.'/public/storage';
$destino = $raiz.'/storage/app/public';

if (! file_exists($enlace)) {
    echo "  no existe\n";
    $problemas[] = 'Falta public/storage. Corre: php artisan storage:link';
} elseif (is_link($enlace)) {
    $apunta = readlink($enlace);
    $resuelto = realpath($enlace);
    echo "  es un enlace           {$apunta}\n";
    echo '  resuelve a             '.($resuelto ?: 'NADA (enlace colgante)')."\n";

    if ($resuelto === false) {
        $problemas[] = 'public/storage apunta a una ruta que no existe en este '
            .'servidor. Borra el enlace y corre php artisan storage:link.';
    } elseif ($resuelto !== realpath($destino)) {
        $problemas[] = 'public/storage no apunta a storage/app/public.';
    }
} else {
    echo "  es una carpeta real, no un enlace\n";
    echo "  (funciona si el contenido esta ahi, pero lo que subas nuevo no aparece)\n";
}

/* ------------------------------------------------------------------ */
echo "\nLOS ARCHIVOS DE LOS ACTIVOS DE MARCA\n";
linea();

$activos = App\Models\BrandAsset::withoutGlobalScopes()
    ->orderByDesc('id')
    ->limit(10)
    ->get();

if ($activos->isEmpty()) {
    echo "  no hay activos cargados\n";
} else {
    foreach ($activos as $a) {
        $disco = $a->storage_disk ?: 'public';
        $s = Illuminate\Support\Facades\Storage::disk($disco);
        $existe = $s->exists($a->storage_path);

        echo "\n  #{$a->id}  {$a->name}\n";
        echo "     disco               {$disco}\n";
        echo "     ruta guardada       {$a->storage_path}\n";
        echo '     en disco            '.($existe ? 'si, '.$s->size($a->storage_path).' bytes' : 'NO ESTA')."\n";

        if ($existe) {
            echo '     URL generada        '.$s->url($a->storage_path)."\n";
            $fisico = $raiz.'/public/storage/'.$a->storage_path;
            echo '     alcanzable por web  '.(is_readable($fisico) ? 'si' : 'no, revisa permisos')."\n";
        } else {
            $problemas[] = "El activo #{$a->id} apunta a un archivo que no esta en "
                .'este servidor. Suele pasar al copiar la base de datos entre '
                .'entornos sin copiar storage/app/public.';
        }

        if ($disco !== 'public') {
            $problemas[] = "El activo #{$a->id} tiene disco \"{$disco}\", pero el "
                .'formulario y la tabla estan fijados a "public".';
        }
    }
}

/* ------------------------------------------------------------------ */
echo "\n";
linea();

if ($problemas === []) {
    echo "Del lado del servidor no se ve nada mal.\n\n";
    echo "Entonces es del lado del navegador. Con la pantalla de edicion abierta,\n";
    echo "F12, pestana Consola, y recarga. Busca un mensaje que diga \"Mixed\n";
    echo "Content\" o \"CORS\": ahi esta la respuesta, y en los dos casos la causa\n";
    echo "es que la URL de arriba no coincide con lo que tienes en la barra de\n";
    echo "direcciones.\n";

    exit(0);
}

echo count($problemas)." cosa(s) que explican el cuelgue:\n\n";

foreach ($problemas as $i => $p) {
    echo '  '.($i + 1).'. '.$p."\n\n";
}

echo "Despues de tocar el .env:  php artisan optimize:clear\n";
