<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
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



Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureApiUserIsActive::class, 'throttle:validaciones'])->prefix('v1')->group(function (): void {
    Route::post('/validaciones', [ValidationController::class, 'store']);
    Route::get('/validaciones/{publicId}', [ValidationController::class, 'show']);
    Route::get('/marcas', [ValidationController::class, 'brands']);
});

/*
| Acceso con correo y contrasena.
|
| Fuera de auth:sanctum, por razones obvias. El limite lo aplica el propio
| controlador contando por correo mas IP, que es mas preciso que un throttle
| por IP: en una agencia todos salen por el mismo router y se bloquearian entre
| si al tercer dedazo de cualquiera.
|
| El throttle de aqui es la segunda linea, contra alguien que pruebe con
| muchos correos distintos desde una misma direccion.
*/
Route::middleware('throttle:20,1')->prefix('v1')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);
});

/*
| Comprobacion de sesion.
|
| Va fuera del grupo con throttle:validaciones a proposito: ese limite es de 20
| por minuto porque cada validacion cuesta dinero. Consultar quien soy no cuesta
| nada, y con el limite compartido el plugin gastaria cupo de validaciones solo
| por arrancar.
|
| Igual lleva su propio limite, mas holgado, para que un plugin con un bucle mal
| escrito no se convierta en una carga.
*/
Route::middleware(['auth:sanctum', \App\Http\Middleware\EnsureApiUserIsActive::class, 'throttle:60,1'])->prefix('v1')->group(function (): void {
    Route::get('/yo', [ValidationController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});