<?php

declare(strict_types=1);

use App\Http\Controllers\Api\ValidationController;

use Illuminate\Support\Facades\Route;

/*
| API de validacion para clientes externos.
|
| Autenticacion con token personal de Sanctum en la cabecera:
|     Authorization: Bearer <token>
|
| El limite de peticiones es deliberadamente bajo: cada llamada dispara una
| validacion real con costo en dolares. Sin limite, un bucle en el plugin
| vacia la cuenta de la API en minutos.
*/



Route::middleware(['auth:sanctum', 'throttle:validaciones'])->prefix('v1')->group(function (): void {
    Route::post('/validaciones', [ValidationController::class, 'store']);
    Route::get('/validaciones/{publicId}', [ValidationController::class, 'show']);
    Route::get('/marcas', [ValidationController::class, 'brands']);
});