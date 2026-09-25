<?php

use Illuminate\Support\Facades\Route;

// Herramienta interna: la raiz lleva al panel. La pagina de bienvenida de
// Laravel no aporta nada y anunciaba rutas de registro que no existen.
Route::redirect('/', '/admin');

/*
| Piezas y activos de marca desde el disco privado, siempre con sesion y
| aplicando la politica del registro (ArchivoController).
*/
Route::middleware(['auth'])->group(function (): void {
    Route::get('/archivos/piezas/{asset:public_id}', [\App\Http\Controllers\ArchivoController::class, 'pieza'])
        ->name('archivos.pieza');

    Route::get('/archivos/activos-marca/{brandAsset}', [\App\Http\Controllers\ArchivoController::class, 'activoMarca'])
        ->name('archivos.activo-marca');
});
