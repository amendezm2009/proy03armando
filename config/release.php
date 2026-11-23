<?php

$publicKeyFile = base_path('config/release-public.key');
$extraKeysDirectory = base_path('config/release-public-keys');

$extraPublicKeys = [];

foreach (glob($extraKeysDirectory.DIRECTORY_SEPARATOR.'*.key') ?: [] as $extraKeyFile) {
    $extraPublicKeys[] = trim((string) file_get_contents($extraKeyFile));
}

return [

    /*
    |--------------------------------------------------------------------------
    | Clave pública de las versiones
    |--------------------------------------------------------------------------
    |
    | Cada versión publicada (php artisan release:package) lleva una firma Ed25519 hecha con una clave PRIVADA que
    | solo existe en el equipo de desarrollo. Esta clave pública (config/release-public.key, se sube al repositorio)
    | sirve para comprobar esa firma: quien no tenga la privada no puede fabricar un paquete que la aplicación acepte.
    | Se crea una sola vez con php artisan release:keygen. Cambiarla invalida las versiones firmadas con la anterior.
    |
    */

    'public_key_file' => $publicKeyFile,

    'public_key' => is_file($publicKeyFile) ? trim((string) file_get_contents($publicKeyFile)) : null,

    /*
    | Rotación de claves: además de la clave principal, la aplicación confía en las claves públicas (una por archivo
    | .key, en Base64) de config/release-public-keys/. Para cambiar de clave sin dejar servidores atrás: 1) release:keygen
    | guarda la nueva pública ahí (la principal sigue siendo la vigente); 2) publicas una versión firmada con la clave
    | ACTUAL que ya lleva la nueva pública; 3) cuando los servidores la instalaron, firmas con la nueva
    | (release:use-key). Esa versión de transición es la "rotación firmada": la clave nueva llega avalada por la vieja.
    */

    'public_keys_directory' => $extraKeysDirectory,

    'extra_public_keys' => $extraPublicKeys,

    /*
    |--------------------------------------------------------------------------
    | Empaquetado
    |--------------------------------------------------------------------------
    |
    | signing_key_file: ruta de la clave privada (FUERA del proyecto) que usa release:package si no se pasa --key.
    | part_size_mb: tamaño máximo de cada parte del ZIP, en MiB. 9 MiB queda por debajo del límite de 10 MB que
    | imponen algunos hostings gratuitos al subir archivos.
    |
    */

    'signing_key_file' => env('RELEASE_SIGNING_KEY_FILE'),

    /*
    | keys_directory: carpeta raíz donde release:keygen crea private/ y public/ (por omisión, <proyecto>_keys junto
    | a la carpeta del proyecto, FUERA de él). key_strength: costo de proteger la clave con la contraseña
    | (moderate o interactive). restrict_permissions: limita la carpeta private/ a tu usuario.
    | key_passphrase: SOLO para automatización (el script lo pone un instante en el entorno del proceso); no lo
    | guardes en el .env, porque la contraseña dejaría de proteger nada.
    */

    'keys_directory' => env('RELEASE_KEYS_DIRECTORY') ?: dirname(base_path()).DIRECTORY_SEPARATOR.basename(base_path()).'_keys',

    'key_strength' => env('RELEASE_KEY_STRENGTH', 'moderate'),

    'restrict_permissions' => (bool) env('RELEASE_RESTRICT_PERMISSIONS', true),

    'key_passphrase' => env('RELEASE_KEY_PASSPHRASE'),

    'part_size_mb' => (int) env('RELEASE_PART_SIZE_MB', 9),

    /*
    | exclude: archivos y carpetas de la RAÍZ del proyecto que no viajan en el paquete porque solo sirven para
    | desarrollar (herramientas de IA, editores, compilación de assets, pruebas). Siempre se excluyen además .git,
    | .github, .env*, node_modules, tests, storage, releases, logs y cachés (ver BuildReleasePackage). Agrega aquí lo
    | que tu proyecto tenga de más (por ejemplo, una colección de pruebas de la API).
    */

    'exclude' => [
        '.agents', '.claude', '.cursor', '.idea', '.vscode', '.zed', '.nova',
        '.mcp.json', 'boost.json', 'CLAUDE.md', 'AGENTS.md',
        'package.json', 'package-lock.json', 'vite.config.js', '.npmrc', 'phpunit.xml', '.editorconfig',
        '2026 laravel armando', 'previews',
    ],

    /*
    |--------------------------------------------------------------------------
    | Comprobación de salud (/health)
    |--------------------------------------------------------------------------
    |
    | La ruta /health responde solo "ok" o "error". Quien envíe este valor en la cabecera X-Health-Token recibe
    | además la versión instalada y el estado de cada comprobación: lo usan los scripts de despliegue para saber
    | que la versión esperada quedó instalada. Sin valor aquí, esos datos no se entregan a nadie.
    |
    */

    'health_token' => env('HEALTH_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Actualizaciones desde la web (/admin/actualizaciones)
    |--------------------------------------------------------------------------
    |
    | updates_directory: carpeta privada donde se reciben los paquetes (inbox/), se trabaja (work/) y se guarda el
    | inventario de archivos de la versión instalada. upload_max_kb: tamaño máximo de cada parte que se sube (10240 =
    | 10 MiB, el límite habitual de los hostings gratuitos). step_seconds: tiempo máximo de trabajo de cada paso de la
    | instalación, para no pasar el límite de ejecución de PHP del hosting.
    |
    */

    'updates_directory' => env('UPDATES_DIRECTORY') ?: storage_path('app/private/updates'),

    'upload_max_kb' => 10240,

    'step_seconds' => 6,

];
