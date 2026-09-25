<?php

/*
| Ajustes operativos propios del validador.
|
| Van aqui y no con env() en routes/console.php: env() fuera de config
| devuelve null con `php artisan config:cache` (lo normal en produccion) y la
| tarea se dejaba de programar sin ningun aviso.
*/
return [
    // Verificacion diaria de huellas de piezas y activos, con aviso en el panel.
    'integridad_programada' => (bool) env('INTEGRIDAD_PROGRAMADA', true),

    // Minutos tras los cuales una validacion sin terminar se cierra como fallida.
    'minutos_validacion_colgada' => (int) env('MINUTOS_VALIDACION_COLGADA', 15),

    // Zona horaria de las tareas programadas.
    'zona_horaria_tareas' => env('ZONA_HORARIA_TAREAS', 'America/Lima'),
];
