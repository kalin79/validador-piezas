<?php

declare(strict_types=1);

/**
 * Explica por que una validacion evaluo las reglas que evaluo, y por que no
 * evaluo las demas.
 *
 * Uso, desde la raiz del proyecto:
 *
 *     php diagnostico-validacion.php            # la ultima ejecucion registrada
 *     php diagnostico-validacion.php 7          # la ultima ejecucion de la pieza 7
 *
 * No modifica nada.
 */

require __DIR__.'/vendor/autoload.php';

$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Asset;
use App\Models\Rule;
use App\Models\RuleSet;
use App\Models\ValidationRun;

$assetId = isset($argv[1]) ? (int) $argv[1] : null;

$run = ValidationRun::query()
    ->when($assetId, fn ($q) => $q->where('asset_id', $assetId))
    ->with(['asset.brand.client', 'asset.submission', 'findings', 'verdict', 'clientRuleSet', 'brandRuleSet'])
    ->orderByDesc('created_at')
    ->first();

if ($run === null) {
    echo PHP_EOL.'No hay ejecuciones registradas'.($assetId ? " para la pieza {$assetId}." : '.').PHP_EOL.PHP_EOL;
    exit(1);
}

$asset = $run->asset;
$brand = $asset->brand;
$client = $brand->client;
$canal = $asset->submission?->channel;

$linea = str_repeat('-', 78);

echo PHP_EOL.$linea.PHP_EOL;
echo "EJECUCION #{$run->id}   pieza #{$asset->id}   {$asset->original_filename}".PHP_EOL;
echo $linea.PHP_EOL;
echo "  Cliente        {$client->name} (id {$client->id})".PHP_EOL;
echo "  Marca          {$brand->name} (id {$brand->id})".PHP_EOL;
echo '  Canal          '.($canal ?? 'SIN CANAL').PHP_EOL;
echo '  Fecha          '.$run->created_at?->format('d/m/Y H:i:s').PHP_EOL;
echo '  Veredicto      '.($run->verdict?->status->label() ?? 'sin veredicto')
    .'  '.($run->verdict?->score !== null ? number_format((float) $run->verdict->score, 1) : '').PHP_EOL;
echo '  Huella         '.substr((string) $run->resolution_hash, 0, 16).'...'.PHP_EOL;

// ---------------------------------------------------------------------------
// 1. Los dos conjuntos que se buscaron
// ---------------------------------------------------------------------------

echo PHP_EOL.'1. CONJUNTOS DE REGLAS'.PHP_EOL.PHP_EOL;

foreach ([['client', 'Cliente', $client->id, $run->clientRuleSet], ['brand', 'Marca', $brand->id, $run->brandRuleSet]] as [$tipo, $etiqueta, $ownerId, $usado]) {
    echo "  {$etiqueta}:".PHP_EOL;

    if ($usado !== null) {
        echo "    USADO   {$usado->name} v{$usado->version}  ({$usado->rules()->count()} reglas)".PHP_EOL;
    } else {
        echo '    NINGUNO  <-- no se encontro conjunto PUBLICADO para este dueno'.PHP_EOL;
    }

    $todos = RuleSet::withTrashed()
        ->where('owner_type', $tipo)
        ->where('owner_id', $ownerId)
        ->orderByDesc('version')
        ->get();

    if ($todos->isEmpty()) {
        echo '    (no existe ningun conjunto para este dueno)'.PHP_EOL;
    }

    foreach ($todos as $rs) {
        $marca = $usado && $rs->id === $usado->id ? '>' : ' ';
        $borrado = $rs->deleted_at ? ' [borrado]' : '';
        echo sprintf(
            "    %s v%-3d %-40s %-12s %2d reglas%s".PHP_EOL,
            $marca, $rs->version, mb_substr($rs->name, 0, 40), $rs->status->label(), $rs->rules()->count(), $borrado
        );
    }

    echo PHP_EOL;
}

// ---------------------------------------------------------------------------
// 2. Regla por regla: entro al snapshot o no, y por que
// ---------------------------------------------------------------------------

echo '2. REGLA POR REGLA'.PHP_EOL.PHP_EOL;

$snapshot = collect($run->resolved_rules_snapshot ?? [])->keyBy('code');
$conHallazgo = $run->findings->pluck('rule_code')->filter()->unique()->flip();

$candidatas = collect();

foreach ([$run->clientRuleSet, $run->brandRuleSet] as $rs) {
    if ($rs === null) {
        continue;
    }

    foreach ($rs->rules()->get() as $rule) {
        $candidatas->push([$rs, $rule]);
    }
}

if ($candidatas->isEmpty()) {
    echo '  No hay reglas en ninguno de los conjuntos publicados.'.PHP_EOL;
}

printf("  %-12s %-8s %-14s %-11s %s".PHP_EOL, 'CODIGO', 'ORIGEN', 'TIPO', 'ESTADO', 'DETALLE');
echo '  '.str_repeat('-', 74).PHP_EOL;

foreach ($candidatas as [$rs, $rule]) {
    /** @var Rule $rule */
    $origen = $rs->owner_type->value === 'client' ? 'cliente' : 'marca';
    $codigoEfectivo = $rule->overrides_code ?? $rule->code;
    $enSnapshot = $snapshot->has($codigoEfectivo)
        && ($snapshot->get($codigoEfectivo)['rule_id'] ?? null) === $rule->id;

    $motivo = '';
    $estado = 'EVALUADA';

    if (! $rule->is_active) {
        $estado = 'EXCLUIDA';
        $motivo = 'la regla esta inactiva';
    } elseif (! $rule->appliesToChannel($canal)) {
        $estado = 'EXCLUIDA';
        $motivo = 'canal: solo aplica a ['.implode(', ', (array) $rule->applies_to_channels).']';
    } elseif (! $enSnapshot) {
        $estado = 'EXCLUIDA';
        $motivo = $snapshot->has($codigoEfectivo)
            ? "anulada por otra regla sobre {$codigoEfectivo}"
            : 'no quedo en el conjunto efectivo';
    } elseif ($conHallazgo->has($rule->code) || $conHallazgo->has($codigoEfectivo)) {
        $estado = 'HALLAZGO';
        $motivo = 'produjo al menos un hallazgo';
    } else {
        $motivo = $rule->type->value === 'deterministic'
            ? 'evaluada por codigo, sin desviacion'
            : 'enviada al modelo, sin observacion';
    }

    printf(
        "  %-12s %-8s %-14s %-11s %s".PHP_EOL,
        $rule->code, $origen, $rule->type->value, $estado, $motivo
    );
}

// ---------------------------------------------------------------------------
// 3. Que dice la propia ejecucion
// ---------------------------------------------------------------------------

$meta = (array) ($run->deterministic_results ?? []);

echo PHP_EOL.'3. LO QUE REGISTRO LA EJECUCION'.PHP_EOL.PHP_EOL;

$deterministicas = $snapshot->filter(fn (array $r): bool => ($r['type'] ?? '') === 'deterministic')->count();
$juicio = $snapshot->count() - $deterministicas;

echo '  Reglas efectivas         '.$snapshot->count()." ({$deterministicas} por codigo, {$juicio} de juicio)".PHP_EOL;
echo '  Hallazgos deterministas  '.($meta['deterministic_findings'] ?? '?').PHP_EOL;
echo '  Motor de IA corrio       '.(($meta['ai_ran'] ?? false) ? 'SI' : 'NO').PHP_EOL;

if ($meta['ai_ran'] ?? false) {
    echo '  IA simulada              '.(($meta['ai_simulated'] ?? false) ? 'SI (driver fake)' : 'no, llamada real').PHP_EOL;
    echo '  Hallazgos de IA          '.($meta['ai_findings'] ?? 0).PHP_EOL;

    if (! empty($meta['ai_discarded'])) {
        echo '  Descartados por filtros:'.PHP_EOL;
        foreach ((array) $meta['ai_discarded'] as $d) {
            echo '    - '.(is_array($d) ? json_encode($d, JSON_UNESCAPED_UNICODE) : $d).PHP_EOL;
        }
    }
}

if (! empty($meta['ai_error'])) {
    echo '  ERROR DE IA              '.$meta['ai_error'].PHP_EOL;
}

if (! empty($meta['evaluator_errors'])) {
    echo '  Errores de evaluadores:'.PHP_EOL;
    foreach ((array) $meta['evaluator_errors'] as $e) {
        echo '    - '.$e.PHP_EOL;
    }
}

echo PHP_EOL.'  Configuracion actual'.PHP_EOL;
echo '    config/ai.php          '.(file_exists(config_path('ai.php')) ? 'presente' : 'AUSENTE (Fase 3 no instalada)').PHP_EOL;
echo '    AI_DRIVER              '.(config('ai.driver') ?: 'no definido').PHP_EOL;
echo '    AI_MODEL               '.(config('ai.model') ?: 'no definido').PHP_EOL;
echo '    Clave cargada          '.(filled(config('ai.anthropic.api_key')) ? 'si' : 'NO').PHP_EOL;
echo '    Plantillas de prompt   '.(class_exists(App\Models\PromptTemplate::class)
    ? App\Models\PromptTemplate::query()->count().' registradas'
    : 'modelo ausente').PHP_EOL;

// ---------------------------------------------------------------------------
// 4. Diagnostico
// ---------------------------------------------------------------------------

echo PHP_EOL.'4. DIAGNOSTICO'.PHP_EOL.PHP_EOL;

$problemas = [];

if ($run->clientRuleSet === null) {
    $problemas[] = "El cliente {$client->name} no tiene ningun conjunto PUBLICADO. Las reglas\n"
        ."    corporativas no se aplicaron. Publica el conjunto de nivel Cliente.";
}

if ($run->brandRuleSet === null) {
    $problemas[] = "La marca {$brand->name} no tiene ningun conjunto PUBLICADO.";
}

if ($juicio === 0 && $snapshot->isNotEmpty()) {
    $problemas[] = "El conjunto efectivo no tiene ninguna regla de juicio, asi que el motor\n"
        ."    de IA no tenia nada que evaluar y no se llamo. Revisa arriba que reglas\n"
        .'    quedaron EXCLUIDAS y por que motivo.';
}

if ($juicio > 0 && ! ($meta['ai_ran'] ?? false)) {
    $problemas[] = "Hay {$juicio} regla(s) de juicio en el conjunto efectivo pero el motor de IA\n"
        .'    no corrio. Suele significar que la Fase 3 no esta instalada o que la\n'
        .'    validacion se lanzo con withAi en falso.';
}

if (($meta['ai_simulated'] ?? false) === true) {
    $problemas[] = "El motor corrio en modo simulado (AI_DRIVER=fake). Los hallazgos de copy,\n"
        ."    tono y cumplimiento son de prueba, no juicios reales. Cambia a\n"
        .'    AI_DRIVER=anthropic con una clave valida.';
}

$excluidasPorCanal = $candidatas->filter(
    fn (array $par): bool => $par[1]->is_active && ! $par[1]->appliesToChannel($canal)
)->count();

if ($excluidasPorCanal > 0) {
    $problemas[] = "{$excluidasPorCanal} regla(s) quedaron fuera porque declaran canales especificos\n"
        ."    y esta pieza es del canal '".($canal ?? 'sin canal')."'. Si deben aplicar siempre,\n"
        .'    deja vacio el campo de canales de esas reglas.';
}

if ($problemas === []) {
    echo '  Sin anomalias: todas las reglas publicadas y activas del cliente y de la'.PHP_EOL;
    echo '  marca entraron al conjunto efectivo y fueron evaluadas.'.PHP_EOL;
} else {
    foreach ($problemas as $i => $p) {
        echo '  '.($i + 1).". {$p}".PHP_EOL.PHP_EOL;
    }
}

echo PHP_EOL;
