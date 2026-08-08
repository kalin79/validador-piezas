<?php

declare(strict_types=1);

/**
 * Repara las plantillas de instrucciones que quedaron sin esquema de salida.
 *
 * Sintoma que corrige: "La salida del modelo no cumple el esquema: falta el
 * campo 'extracted_text'". Sin output_schema, AiEvaluator le pasa al proveedor
 * un ['type' => 'object'] vacio y el modelo responde en formato libre.
 *
 * Uso:
 *   php artisan tinker --execute="require 'reparar-plantillas.php';"
 */

use App\Models\PromptTemplate;

$todas = PromptTemplate::query()->orderBy('key')->orderByDesc('version')->get();

echo 'Plantillas encontradas: '.$todas->count().PHP_EOL.PHP_EOL;

printf("%-4s %-30s %-22s %-9s %-8s %s\n", 'id', 'nombre', 'clave', 'version', 'estado', 'esquema');
echo str_repeat('-', 90).PHP_EOL;

foreach ($todas as $t) {
    printf(
        "%-4d %-30s %-22s %-9s %-8s %s\n",
        $t->id,
        mb_substr((string) $t->name, 0, 29),
        (string) $t->key,
        (string) $t->version,
        $t->status->value,
        blank($t->output_schema) ? 'AUSENTE' : 'ok ('.count($t->output_schema['properties'] ?? []).' props)',
    );
}

$sinEsquema = $todas->filter(static fn (PromptTemplate $t): bool => blank($t->output_schema));

if ($sinEsquema->isEmpty()) {
    echo PHP_EOL.'Todas tienen esquema. El problema es otro: revisa el prompt del sistema.'.PHP_EOL;

    return;
}

echo PHP_EOL.'Sin esquema: '.$sinEsquema->count().PHP_EOL;

$reparadas = 0;

foreach ($sinEsquema as $t) {
    $modelo = PromptTemplate::query()
        ->where('key', $t->key)
        ->whereNotNull('output_schema')
        ->orderByDesc('version')
        ->first();

    if ($modelo === null) {
        echo '  #'.$t->id.' "'.$t->name.'" -> no hay ninguna plantilla con esquema para la clave "'.$t->key.'".'.PHP_EOL;
        echo '     Vuelve a correr el seeder: php artisan db:seed --class=PromptTemplateSeeder'.PHP_EOL;

        continue;
    }

    $t->forceFill(['output_schema' => $modelo->output_schema])->save();
    $reparadas++;

    echo '  #'.$t->id.' "'.$t->name.'" -> esquema copiado de #'.$modelo->id.PHP_EOL;
}

echo PHP_EOL.'Reparadas: '.$reparadas.PHP_EOL;
echo 'Revalida la pieza para comprobar.'.PHP_EOL;
