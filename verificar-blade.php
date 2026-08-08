<?php

declare(strict_types=1);

/**
 * Compila plantillas Blade y hace lint del PHP resultante.
 *
 * Sirve para atrapar en un segundo los errores de sintaxis que de otro modo
 * solo aparecen cuando alguien abre la pantalla, y con un mensaje que apunta
 * al final del archivo en vez de a la causa.
 *
 * Uso, desde la raiz del proyecto:
 *
 *     php verificar-blade.php                                   # todas las vistas del proyecto
 *     php verificar-blade.php resources/views/filament/modals    # solo esa carpeta
 *
 * No modifica nada del proyecto. Escribe temporales en storage/framework/.
 */

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Blade;

$raiz = $argv[1] ?? 'resources/views';
$raiz = rtrim($raiz, '/');

if (! is_dir($raiz)) {
    echo PHP_EOL."No existe el directorio: {$raiz}".PHP_EOL.PHP_EOL;
    exit(1);
}

$archivos = [];
$iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz));

foreach ($iterador as $archivo) {
    if ($archivo->isFile() && str_ends_with($archivo->getFilename(), '.blade.php')) {
        $archivos[] = $archivo->getPathname();
    }
}

sort($archivos);

$temporal = storage_path('framework/blade-check.php');

echo PHP_EOL.'Compilando '.count($archivos).' plantilla(s) bajo '.$raiz.PHP_EOL.PHP_EOL;

$fallos = [];

foreach ($archivos as $ruta) {
    $relativa = str_replace(base_path().'/', '', $ruta);

    try {
        $compilado = Blade::compileString((string) file_get_contents($ruta));
    } catch (Throwable $e) {
        $fallos[] = [$relativa, 'no compila: '.$e->getMessage()];
        echo "  ROTA  {$relativa}".PHP_EOL;

        continue;
    }

    file_put_contents($temporal, $compilado);

    $salida = [];
    $codigo = 0;
    exec('php -l '.escapeshellarg($temporal).' 2>&1', $salida, $codigo);

    if ($codigo !== 0) {
        $detalle = trim(implode(' ', $salida));
        $detalle = str_replace($temporal, '(compilado)', $detalle);

        $fallos[] = [$relativa, $detalle];
        echo "  ROTA  {$relativa}".PHP_EOL;

        continue;
    }

    echo "  ok    {$relativa}".PHP_EOL;
}

if (file_exists($temporal)) {
    unlink($temporal);
}

echo PHP_EOL;

if ($fallos === []) {
    echo 'Todas compilan y el PHP generado es valido.'.PHP_EOL.PHP_EOL;
    exit(0);
}

echo 'PLANTILLAS CON PROBLEMA'.PHP_EOL.PHP_EOL;

foreach ($fallos as [$archivo, $detalle]) {
    echo "  {$archivo}".PHP_EOL;
    echo "    {$detalle}".PHP_EOL.PHP_EOL;
}

echo 'El numero de linea del error corresponde al PHP compilado, no al .blade.php.'.PHP_EOL;
echo 'Las causas mas frecuentes son directivas que Blade no analiza bien:'.PHP_EOL;
echo '  - @class o @style con el arreglo repartido en varias lineas'.PHP_EOL;
echo '  - @forelse con una expresion que lleva comas o funciones flecha'.PHP_EOL;
echo '  - funciones anonimas dentro de @php ... @endphp'.PHP_EOL.PHP_EOL;

exit(1);
