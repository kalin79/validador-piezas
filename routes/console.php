<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Respaldos
|--------------------------------------------------------------------------
|
| Todo esto queda inerte mientras BACKUP_ENABLED sea false, que es el valor
| por defecto. Los comandos existen y se pueden correr a mano; lo que no
| ocurre es la programacion automatica.
|
| Se hizo asi a proposito: intentar subir cada noche a un destino sin
| credenciales llena el log de errores, y un log lleno de ruido es un log que
| nadie mira. Mejor apagado y explicito que encendido y fallando.
|
| PARA ACTIVARLO, el dia que haya servidor y credenciales:
|
|   1. BACKUP_ENABLED=true en el .env
|   2. Configurar el disco 'respaldos' y sus credenciales
|   3. php artisan config:clear
|   4. Agregar al crontab del usuario que sirve la aplicacion:
|        * * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
|   5. Comprobar con: php artisan schedule:list
|
| Sin el paso 4 nada corre, y el sistema no avisa.
|
*/

if (config('backup.enabled')) {
    // Limpieza antes del respaldo, para no quedarse sin disco justo al escribir.
    Schedule::command('backup:clean')
        ->daily()
        ->at('01:30')
        ->onOneServer()
        ->withoutOverlapping();

    Schedule::command('backup:run')
        ->daily()
        ->at('02:00')
        ->onOneServer()
        ->withoutOverlapping()
        ->runInBackground();

    /*
     * La prueba de restauracion semanal.
     *
     * Es lo que distingue un respaldo real de una carpeta con archivos.
     * Restaura el ultimo zip en una base desechable, cuenta las tablas de
     * auditoria y borra la base al terminar. Nunca toca produccion.
     */
    Schedule::command('respaldo:probar')
        ->weeklyOn(0, '03:30')
        ->onOneServer()
        ->withoutOverlapping();

    /*
     * Vigilancia: avisa si el respaldo mas reciente es demasiado viejo. Sin
     * esto, uno que dejo de correr hace tres semanas se descubre el dia del
     * incidente.
     */
    Schedule::command('backup:monitor')
        ->daily()
        ->at('08:00')
        ->onOneServer();
}

/*
 * Verificacion de huellas: diaria, con aviso en el panel a los super_admin
 * cuando aparecen problemas nuevos. No necesita credenciales ni destino
 * externo. Se apaga con INTEGRIDAD_PROGRAMADA=false.
 */
if (config('validador.integridad_programada')) {
    Schedule::command('integridad:verificar --avisar')
        ->dailyAt('06:45')
        ->timezone(config('validador.zona_horaria_tareas'))
        ->onOneServer()
        ->withoutOverlapping();
}

/*
 * Cierra las validaciones que quedaron colgadas (proceso muerto, timeout,
 * despliegue). Sin esto una pieza queda "validandose" para siempre.
 */
Schedule::command('validaciones:cerrar-colgadas --minutos='.(int) config('validador.minutos_validacion_colgada', 15))
    ->everyTenMinutes()
    ->onOneServer()
    ->withoutOverlapping();
