<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Respaldos de la base de datos
    |--------------------------------------------------------------------------
    |
    | php artisan db:backup guarda aquí un volcado comprimido (.sql.gz) y un archivo .json con su SHA-256, la versión
    | de la aplicación y las tablas. Es una carpeta privada: en un hosting donde la raíz del sitio es la raíz del
    | proyecto, déjala FUERA de la carpeta pública (BACKUP_DIRECTORY) porque el volcado contiene datos de usuarios.
    |
    | keep: cuántos respaldos se conservan (los más recientes); 0 desactiva la limpieza.
    | exclude_data: tablas de las que solo se guarda la estructura, porque son temporales (sesiones, caché).
    | rows_per_insert: filas por instrucción INSERT del volcado.
    |
    */

    'directory' => env('BACKUP_DIRECTORY') ?: storage_path('app/private/backups'),

    'keep' => (int) env('BACKUP_KEEP', 10),

    'exclude_data' => ['sessions', 'cache', 'cache_locks'],

    'rows_per_insert' => 100,

];
