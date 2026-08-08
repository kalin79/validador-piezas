<?php

declare(strict_types=1);

/**
 * Revalida la pieza de la ultima ejecucion, en directo y sin pasar por la cola.
 *
 * Uso, desde la raiz del proyecto:
 *   php artisan tinker --execute="require 'revalidar.php';"
 */

$anterior = App\Models\ValidationRun::query()->latest('id')->first();

if ($anterior === null) {
    echo 'No hay ninguna ejecucion previa de la cual tomar la pieza.'.PHP_EOL;

    return;
}

$asset = App\Models\Asset::query()->find($anterior->asset_id);

if ($asset === null) {
    echo 'La pieza de la ejecucion #'.$anterior->id.' ya no existe.'.PHP_EOL;

    return;
}

echo 'Revalidando: '.$asset->original_filename.PHP_EOL;
echo 'Marca: '.($asset->brand?->name ?? 'sin marca').PHP_EOL;
echo str_repeat('-', 62).PHP_EOL;

$inicio = microtime(true);

$run = app(App\Services\ValidationRunner::class)->run($asset);

$segundos = round(microtime(true) - $inicio, 1);

echo 'Ejecucion #'.$run->id.'   (la anterior era #'.$anterior->id.')'.PHP_EOL;
echo 'Duracion:  '.$segundos.' s'.PHP_EOL;
echo 'Estado:    '.$run->status->value.PHP_EOL;
echo 'Modelo:    '.($run->model_identifier ?: '(no se llamo)').PHP_EOL;
echo 'Mensaje:   '.($run->error_message ?: '(ninguno)').PHP_EOL;
echo 'Costo:     $'.($run->cost_usd ?? 0).PHP_EOL;
echo 'Tokens:    '.($run->input_tokens ?? 0).' entrada / '.($run->output_tokens ?? 0).' salida'.PHP_EOL;
echo 'Hallazgos: '.$run->findings()->count().PHP_EOL;
echo 'Puntaje:   '.($run->score ?? '-').PHP_EOL;
echo PHP_EOL;

print_r($run->deterministic_results);

if ($run->id === $anterior->id) {
    echo PHP_EOL.'>>> No se creo una ejecucion nueva. Algo impidio que el runner corriera.'.PHP_EOL;
}
