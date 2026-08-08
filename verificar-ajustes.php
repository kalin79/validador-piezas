<?php

declare(strict_types=1);

/**
 * Checklist de los ajustes acordados tras la calibracion de la ejecucion #42.
 *
 * No modifica nada: solo mira el codigo y la base de datos y reporta que
 * quedo aplicado y que falta.
 *
 * Uso:
 *   php artisan tinker --execute="require 'verificar-ajustes.php';"
 */

use App\Models\Palette;
use App\Models\PromptTemplate;
use App\Models\Rule;
use App\Models\RuleSet;
use App\Models\ValidationRun;

$ok = 0;
$falta = 0;

function chequeo(string $titulo, bool $cumple, string $detalleOk = '', string $detalleFalta = ''): void
{
    global $ok, $falta;

    $cumple ? $ok++ : $falta++;

    echo ($cumple ? '  [ OK ]   ' : '  [FALTA]  ').$titulo.PHP_EOL;

    $detalle = $cumple ? $detalleOk : $detalleFalta;

    if ($detalle !== '') {
        echo '           '.$detalle.PHP_EOL;
    }
}

function seccion(string $t): void
{
    echo PHP_EOL.str_repeat('=', 72).PHP_EOL.$t.PHP_EOL.str_repeat('=', 72).PHP_EOL;
}

function archivo(string $rel): string
{
    $ruta = base_path($rel);

    return is_file($ruta) ? (string) file_get_contents($ruta) : '';
}

// =====================================================================
seccion('1. VEREDICTO');

$enum = archivo('app/Enums/VerdictStatus.php');
chequeo(
    'VerdictStatus tiene el estado NotEvaluated',
    str_contains($enum, 'NotEvaluated'),
    '',
    'Falta aplicar fix-veredicto-sin-evaluar.zip',
);

$calc = archivo('app/Services/Validation/VerdictCalculator.php');
chequeo(
    'VerdictCalculator recibe cuantas reglas se aplicaron',
    str_contains($calc, 'rulesApplied'),
);

$runner = archivo('app/Services/ValidationRunner.php');
chequeo(
    'ValidationRunner le pasa el conteo de reglas',
    str_contains($runner, 'reglasAplicadas'),
);

// =====================================================================
seccion('2. INSTRUCCIONES DEL MODELO');

$plantillas = PromptTemplate::query()->where('status', 'published')->get();

echo '  Plantillas publicadas: '.$plantillas->count().PHP_EOL;

$frases = [
    'solo incumplimientos' => 'exclusivamente incumplimientos',
    'no juzgar hechos externos' => 'No emitas juicios sobre hechos del mundo',
    'usar solo codigos dados' => 'No inventes codigos',
    'pedir text_blocks' => 'text_blocks',
];

foreach ($plantillas as $t) {
    echo PHP_EOL.'  Plantilla #'.$t->id.' "'.$t->name.'"'.PHP_EOL;

    $texto = ($t->system_prompt ?? '').' '.($t->user_prompt_template ?? '');

    foreach ($frases as $etiqueta => $aguja) {
        chequeo('  '.$etiqueta, str_contains($texto, $aguja));
    }

    chequeo(
        '  tiene esquema de salida',
        filled($t->output_schema),
        count($t->output_schema['properties'] ?? []).' propiedades',
        'Corre reparar-plantillas.php',
    );
}

// =====================================================================
seccion('3. ENUNCIADOS DE REGLAS');

$esperado = [
    'COMP-002' => ['aguja' => 'No juzgues', 'que' => 'no juzgar si el ranking existe'],
    'COMP-006' => ['aguja' => 'ausencia', 'que' => 'la ausencia no es incumplimiento'],
    'COMP-005' => ['aguja' => 'ausencia', 'que' => 'la ausencia no es incumplimiento'],
    'COPY-003' => ['aguja' => 'ausencia', 'que' => 'la ausencia no es falta'],
    'TYPO-002' => ['aguja' => 'COPY', 'que' => 'remite los errores de texto a las reglas COPY'],
];

foreach ($esperado as $codigo => $def) {
    $reglas = Rule::query()->where('code', $codigo)->get();

    if ($reglas->isEmpty()) {
        chequeo($codigo.' existe', false, '', 'no se encontro la regla');

        continue;
    }

    foreach ($reglas as $r) {
        chequeo(
            $codigo.' (conjunto '.$r->rule_set_id.') — '.$def['que'],
            str_contains(mb_strtolower($r->statement), mb_strtolower($def['aguja'])),
            '',
            'el enunciado no menciona: '.$def['aguja'],
        );
    }
}

// =====================================================================
seccion('4. PALETA');

$paletas = Palette::query()->where('is_active', true)->with('colors')->get();

echo '  Paletas activas: '.$paletas->count().PHP_EOL.PHP_EOL;

foreach ($paletas as $p) {
    $hexes = $p->colors->pluck('hex')->map(fn ($h) => mb_strtoupper((string) $h))->all();

    echo '  "'.$p->name.'" (marca '.$p->brand_id.') — '.count($hexes).' colores'.PHP_EOL;

    chequeo('  neutro fotografico #B0B3B5', in_array('#B0B3B5', $hexes, true));
    chequeo(
        '  azul profundo #051744',
        in_array('#051744', $hexes, true),
        '',
        'opcional: solo si marca lo autorizo',
    );
}

// =====================================================================
seccion('5. CONJUNTO Y REVALIDACION');

$conjuntos = RuleSet::query()
    ->where('status', 'published')
    ->orderBy('owner_type')
    ->orderByDesc('version')
    ->get();

echo '  Conjuntos publicados:'.PHP_EOL;

foreach ($conjuntos as $c) {
    echo '    #'.$c->id.' v'.$c->version.'  '.$c->owner_type->value.' '.$c->owner_id.'  "'.$c->name.'"'.PHP_EOL;
}

$c14 = RuleSet::query()->find(14);
chequeo(
    'El conjunto 14 esta publicado',
    $c14?->status->value === 'published',
    '',
    'sigue en '.($c14?->status->value ?? 'no existe'),
);

$ultima = ValidationRun::query()->latest('id')->first();

chequeo(
    'Hay una validacion posterior a la #42',
    $ultima !== null && $ultima->id > 42,
    'la ultima es la #'.($ultima?->id ?? '-'),
    'la ultima sigue siendo la #'.($ultima?->id ?? '-').': falta revalidar',
);

if ($ultima !== null && $ultima->id > 42) {
    echo PHP_EOL.'  Comparacion con la ejecucion #42:'.PHP_EOL;

    $ref = ValidationRun::query()->find(42);

    printf(
        "    %-22s %-12s %-12s\n",
        '',
        '#42',
        '#'.$ultima->id,
    );
    printf(
        "    %-22s %-12s %-12s\n",
        'hallazgos',
        $ref?->findings()->count() ?? '-',
        $ultima->findings()->count(),
    );
    printf(
        "    %-22s %-12s %-12s\n",
        'estado',
        $ref?->verdict?->status->value ?? '-',
        $ultima->verdict?->status->value ?? '-',
    );
    printf(
        "    %-22s %-12s %-12s\n",
        'puntaje',
        $ref?->verdict?->score ?? '-',
        $ultima->verdict?->score ?? '-',
    );
    printf(
        "    %-22s %-12s %-12s\n",
        'bloqueantes',
        $ref?->verdict?->blocking_count ?? '-',
        $ultima->verdict?->blocking_count ?? '-',
    );
    printf(
        "    %-22s %-12s %-12s\n",
        'costo USD',
        $ref?->cost_usd ?? '-',
        $ultima->cost_usd ?? '-',
    );
}

// =====================================================================
seccion('RESUMEN');

echo '  Aplicados: '.$ok.PHP_EOL;
echo '  Faltantes: '.$falta.PHP_EOL;

echo PHP_EOL.($falta === 0
    ? '  Todo lo acordado esta aplicado.'
    : '  Revisa las lineas [FALTA] de arriba.').PHP_EOL;
