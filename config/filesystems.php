<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    | Disco de las piezas y activos de marca.
    |
    | Privado por diseno: las piezas son campanas sin publicar. Se sirven solo
    | a traves de rutas autenticadas que aplican la politica del registro
    | (ver ArchivoController). Nunca debe apuntar a 'public'.
    */
    'piezas_disk' => env('PIEZAS_DISK', 'local'),

    // Tope de resolucion de una pieza, en megapixeles. Ver LimiteDePixeles.
    'piezas_max_megapixeles' => (int) env('PIEZAS_MAX_MEGAPIXELES', 50),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Destino externo de los respaldos (S3 o compatible: Backblaze B2,
         * Cloudflare R2, DigitalOcean Spaces). Un respaldo en el mismo
         * servidor se pierde con el mismo incidente. Se activa con
         * BACKUP_DISK=respaldos. Ver docs/DESPLIEGUE.md.
         */
        'respaldos' => [
            'driver' => 's3',
            'key' => env('RESPALDOS_KEY'),
            'secret' => env('RESPALDOS_SECRET'),
            'region' => env('RESPALDOS_REGION', 'us-east-1'),
            'bucket' => env('RESPALDOS_BUCKET'),
            'endpoint' => env('RESPALDOS_ENDPOINT'),
            'use_path_style_endpoint' => (bool) env('RESPALDOS_PATH_STYLE', false),
            'visibility' => 'private',
            'throw' => true,
            'report' => true,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
