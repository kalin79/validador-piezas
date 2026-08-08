<?php

declare(strict_types=1);

/**
 * Encuentra y repara campos JSON de reglas que no contienen un arreglo.
 *
 * Sintoma que corrige:
 *   TypeError en vendor/filament/forms/src/Components/Repeater.php
 *   array_map(): Argument #2 ($array) must be of type array, string given
 *
 * El Repeater de ejemplos espera un arreglo. Si la columna guarda una cadena
 * —tipico del doble json_encode, o de una edicion por SQL— el formulario
 * revienta al hidratarse, con un 500 que no dice cual registro lo causo.
 *
 * Uso:
 *   php artisan tinker --execute="require 'reparar-campos-json.php';"
 *
 * Para solo diagnosticar, sin escribir:
 *   REPARAR=0 php artisan tinker --execute="require 'reparar-campos-json.php';"
 */

use App\Models\Rule;
use Illuminate\Support\Facades\DB;

$reparar = getenv('REPARAR') !== '0';

$columnas = ['positive_examples', 'negative_examples', 'applies_to_channels', 'parameters'];

echo $reparar ? 'MODO REPARACION' : 'MODO DIAGNOSTICO (no escribe)';
echo PHP_EOL.str_repeat('=', 78).PHP_EOL.PHP_EOL;

// Se lee crudo con el query builder: el cast del modelo es justamente lo que
// oculta el problema, porque convierte en silencio lo que puede.
$filas = DB::table('rules')->get(array_merge(['id', 'rule_set_id', 'code'], $columnas));

$rotas = [];

foreach ($filas as $fila) {
    foreach ($columnas as $col) {
        $crudo = $fila->{$col};

        if ($crudo === null || $crudo === '') {
            continue;
        }

        $decodificado = json_decode((string) $crudo, true);

        if (is_array($decodificado)) {
            continue;
        }

        $rotas[] = [
            'id' => $fila->id,
            'code' => $fila->code,
            'columna' => $col,
            'crudo' => $crudo,
            'decodificado' => $decodificado,
        ];
    }
}

if ($rotas === []) {
    echo 'Ninguna columna JSON de rules contiene algo que no sea un arreglo.'.PHP_EOL;
    echo PHP_EOL.'El error viene de otro lado. Revisa:'.PHP_EOL;
    echo '  - otras tablas con Repeater en su formulario (palettes, brand_assets)'.PHP_EOL;
    echo '  - si el error aparece al escribir en el campo, no al abrirlo'.PHP_EOL;

    return;
}

echo 'Valores que no son arreglo: '.count($rotas).PHP_EOL.PHP_EOL;

printf("%-5s %-12s %-22s %s\n", 'id', 'codigo', 'columna', 'contenido crudo');
echo str_repeat('-', 78).PHP_EOL;

foreach ($rotas as $r) {
    printf(
        "%-5d %-12s %-22s %s\n",
        $r['id'],
        $r['code'],
        $r['columna'],
        mb_substr(var_export($r['crudo'], true), 0, 40),
    );
}

if (! $reparar) {
    echo PHP_EOL.'Nada se modifico. Corre sin REPARAR=0 para arreglarlo.'.PHP_EOL;

    return;
}

echo PHP_EOL.'Reparando...'.PHP_EOL;

$arregladas = 0;

foreach ($rotas as $r) {
    $valor = $r['decodificado'];

    // Doble codificado: el decode devolvio una cadena que a su vez es JSON.
    if (is_string($valor)) {
        $segundo = json_decode($valor, true);
        $nuevo = is_array($segundo) ? $segundo : [$valor];
    } elseif ($valor === null) {
        // No era JSON valido: se trata como un unico elemento de texto.
        $nuevo = [(string) $r['crudo']];
    } else {
        $nuevo = [$valor];
    }

    // Un arreglo vacio se guarda como nulo: es lo que el formulario espera
    // para un repeater sin elementos, y evita distinguir entre "vacio" y
    // "nunca se lleno".
    $final = $nuevo === [] ? null : json_encode($nuevo, JSON_UNESCAPED_UNICODE);

    DB::table('rules')->where('id', $r['id'])->update([$r['columna'] => $final]);

    $arregladas++;

    echo '  #'.$r['id'].' '.$r['code'].' · '.$r['columna'].' -> '.($final ?? 'null').PHP_EOL;
}

echo PHP_EOL.'Reparadas: '.$arregladas.PHP_EOL;
echo 'Vuelve a abrir el conjunto en el panel.'.PHP_EOL;

// Verificacion: se releen por el modelo, que es como los lee Filament.
echo PHP_EOL.'Verificando con los casts del modelo...'.PHP_EOL;

$malas = Rule::query()->get()->filter(static function (Rule $r) use ($columnas): bool {
    foreach ($columnas as $col) {
        $v = $r->{$col};

        if ($v !== null && ! is_array($v)) {
            return true;
        }
    }

    return false;
});

echo $malas->isEmpty()
    ? 'Todas las reglas devuelven arreglo o nulo. El formulario deberia abrir.'.PHP_EOL
    : 'Quedan '.$malas->count().' con problema: '.$malas->pluck('code')->implode(', ').PHP_EOL;
