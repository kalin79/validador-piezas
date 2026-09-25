<?php

/*
| Solo se sobrescribe la subida temporal. El resto de la configuracion de
| Livewire sale de sus valores por defecto (mergeConfigFrom).
|
| Motivo: sin este archivo los temporales iban al disco por defecto, que en
| este proyecto era 'public'. Eso dejaba livewire-tmp/ accesible por /storage
| con la extension que eligiera quien subia el archivo.
*/
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK', 'local'),
        // El sistema solo acepta imagenes rasterizadas. Se valida ya en la
        // subida temporal, antes de que el archivo toque ningun formulario.
        'rules' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
        'directory' => null,
        'middleware' => 'throttle:60,1',
        'preview_mimes' => ['png', 'jpg', 'jpeg', 'webp'],
        'max_upload_time' => 5,
        'cleanup' => true,
    ],
];
