<?php

declare(strict_types=1);

/**
 * Alinea los textos del menu del panel.
 *
 *   php alinear-menu.php            solo mira y reporta
 *   php alinear-menu.php --aplicar  escribe los cambios
 *
 * Dos cosas:
 *
 * 1. Tildes. En el codigo las cadenas internas se escribieron sin acentos, y
 *    eso se colo a los textos que ve el usuario. El resultado es un menu donde
 *    "Configuracion" y "Operacion" conviven con "Piezas validadas": se lee
 *    descuidado, y son los dos grupos que aparecen en todas las pantallas.
 *
 * 2. Activos de marca. El recurso no declaraba grupo ni etiqueta, asi que
 *    Filament lo listaba suelto arriba del todo con el nombre generado del
 *    modelo, "Brand Assets", en ingles y entre trece pantallas en espanol.
 *    Va en Base de conocimiento porque define que debe cumplir la pieza, no
 *    es un dato de la empresa.
 *
 * Solo toca texto visible. Nada funcional cambia.
 */

$raiz = __DIR__;
$aplicar = in_array('--aplicar', $argv, true);

/*
 * Se busca con el prefijo de la propiedad y no la cadena suelta: "Operacion"
 * a secas aparece tambien en comentarios y en textos de ayuda que no son el
 * menu, y ahi no hay que tocar nada.
 */
$textos = [
    // Grupos: los mas visibles, salen en la barra lateral siempre.
    "navigationGroup = 'Configuracion'" => "navigationGroup = 'Configuración'",
    "navigationGroup = 'Operacion'" => "navigationGroup = 'Operación'",

    // Etiquetas del menu.
    "navigationLabel = 'Revision'" => "navigationLabel = 'Revisión'",
    "navigationLabel = 'Validacion rapida'" => "navigationLabel = 'Validación rápida'",

    // Titulos de pagina.
    "\$title = 'Revision humana'" => "\$title = 'Revisión humana'",
    "\$title = 'Validacion rapida'" => "\$title = 'Validación rápida'",

    // Nombre del modelo en singular: va dentro de frases como "Crear ...".
    "modelLabel = 'instruccion'" => "modelLabel = 'instrucción'",
    // No es una tilde: es concordancia. Un conjunto agrupa varias reglas.
    "modelLabel = 'conjunto de regla'" => "modelLabel = 'conjunto de reglas'",

    /*
     * El plural encabeza la pagina de listado, asi que va con mayuscula
     * inicial. Hoy conviven "Paletas" y "clientes", y la diferencia se ve
     * al cambiar de pantalla.
     */
    "pluralModelLabel = 'cargas'" => "pluralModelLabel = 'Cargas'",
    "pluralModelLabel = 'clientes'" => "pluralModelLabel = 'Clientes'",
    "pluralModelLabel = 'instrucciones'" => "pluralModelLabel = 'Instrucciones'",
    "pluralModelLabel = 'marcas'" => "pluralModelLabel = 'Marcas'",
    "pluralModelLabel = 'piezas'" => "pluralModelLabel = 'Piezas'",
];

/* Bloque que ubica Activos de marca en su grupo. */
$anclaActivos = '    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;';

$bloqueActivos = <<<'PHP'

    /*
     * Sin estas lineas el recurso quedaba fuera de los cuatro grupos del
     * panel: Filament lo listaba suelto arriba y con el nombre generado del
     * modelo, "Brand Assets", en ingles.
     *
     * Va en Base de conocimiento y no en Configuracion porque un activo de
     * marca no es un dato de la empresa: define que debe cumplir la pieza
     * —que el logo aparezca, con que tamano y en que posicion—, igual que una
     * regla. De hecho sus hallazgos se reportan bajo la regla de categoria
     * "activos obligatorios" del conjunto vigente.
     */
    protected static string|UnitEnum|null $navigationGroup = 'Base de conocimiento';

    protected static ?string $navigationLabel = 'Activos de marca';

    protected static ?string $modelLabel = 'activo de marca';

    protected static ?string $pluralModelLabel = 'Activos de marca';

    protected static ?int $navigationSort = 4;
PHP;

/** @return array<int, string> */
function archivosPhp(string $dir): array
{
    $salida = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $salida[] = $f->getPathname();
        }
    }

    sort($salida);

    return $salida;
}

$base = $raiz.'/app/Filament';

if (! is_dir($base)) {
    echo "No existe app/Filament. Corre esto desde la raiz del proyecto.\n";

    exit(1);
}

$pendientes = [];
$total = 0;

foreach (archivosPhp($base) as $ruta) {
    $original = file_get_contents($ruta);
    $contenido = $original;
    $hechos = [];

    foreach ($textos as $de => $a) {
        if (! str_contains($contenido, $de)) {
            continue;
        }

        $contenido = str_replace($de, $a, $contenido);
        $hechos[] = trim(explode('=', $de)[1] ?? $de);
    }

    if ($contenido !== $original) {
        $pendientes[$ruta] = ['contenido' => $contenido, 'detalle' => $hechos];
        $total += count($hechos);
    }
}

/* Activos de marca, aparte porque no es un reemplazo sino una insercion. */
$rutaActivos = $base.'/Resources/BrandAssets/BrandAssetResource.php';
$activosPendiente = false;

if (is_file($rutaActivos)) {
    $c = $pendientes[$rutaActivos]['contenido'] ?? file_get_contents($rutaActivos);

    if (! str_contains($c, "navigationLabel = 'Activos de marca'")) {
        if (! str_contains($c, $anclaActivos)) {
            echo "Aviso: no se encontro donde insertar el grupo en BrandAssetResource. Revisalo a mano.\n";
        } else {
            $c = str_replace($anclaActivos, $anclaActivos."\n".$bloqueActivos, $c);

            // El bloque usa UnitEnum en el tipo de la propiedad.
            if (! str_contains($c, 'use UnitEnum;')) {
                $c = str_replace(
                    'use Illuminate\Database\Eloquent\SoftDeletingScope;',
                    "use Illuminate\Database\Eloquent\SoftDeletingScope;\nuse UnitEnum;",
                    $c
                );
            }

            $pendientes[$rutaActivos] = [
                'contenido' => $c,
                'detalle' => array_merge(
                    $pendientes[$rutaActivos]['detalle'] ?? [],
                    ['ubicado en Base de conocimiento como "Activos de marca"']
                ),
            ];
            $activosPendiente = true;
            $total++;
        }
    }
}

if ($pendientes === []) {
    echo "El menu ya esta alineado. No hay nada que cambiar.\n";

    exit(0);
}

echo ($aplicar ? "APLICANDO" : "PENDIENTE")." · {$total} cambio(s) en ".count($pendientes)." archivo(s)\n";
echo str_repeat('-', 72)."\n";

$errores = 0;

foreach ($pendientes as $ruta => $datos) {
    $corto = str_replace($raiz.'/', '', $ruta);
    echo "\n{$corto}\n";

    foreach ($datos['detalle'] as $d) {
        echo "  · {$d}\n";
    }

    if (! $aplicar) {
        continue;
    }

    // Se valida antes de escribir: un archivo roto en Filament tumba el panel.
    $tmp = tempnam(sys_get_temp_dir(), 'menu').'.php';
    file_put_contents($tmp, $datos['contenido']);
    exec(PHP_BINARY.' -l '.escapeshellarg($tmp).' 2>&1', $salida, $codigo);
    @unlink($tmp);

    if ($codigo !== 0) {
        echo "  [ABORTADO] no compila: ".implode(' ', $salida)."\n";
        $errores++;

        continue;
    }

    copy($ruta, $ruta.'.bak');
    file_put_contents($ruta, $datos['contenido']);
    echo "  escrito (copia en .bak)\n";
}

echo "\n".str_repeat('-', 72)."\n";

if (! $aplicar) {
    echo "Nada escrito. Para aplicarlo:  php alinear-menu.php --aplicar\n";

    exit(0);
}

if ($errores > 0) {
    echo "{$errores} archivo(s) no se escribieron. Revisalos a mano.\n";

    exit(1);
}

echo "Listo. Ahora:\n";
echo "  php artisan optimize:clear && php artisan filament:optimize-clear\n";

if ($activosPendiente) {
    echo "\nActivos de marca aparece ahora en Base de conocimiento, cuarto de la lista.\n";
}
