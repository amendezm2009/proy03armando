<?php

/*
|--------------------------------------------------------------------------
| Límites de peticiones por minuto
|--------------------------------------------------------------------------
|
| Cada clave es el nombre de un limitador que App\Providers\AppServiceProvider
| registra y que las rutas aplican con el middleware "throttle:{nombre}".
| Al superar un límite se responde 429 (página propia en la web, JSON en la API).
|
| Los valores se leen del .env aquí, y no con env() en el código, para que
| sigan funcionando con la configuración cacheada (php artisan config:cache).
|
*/

return [

    // Toda la API, por usuario o por IP si la petición no lleva token.
    'api' => (int) env('API_REQUESTS_PER_MINUTE', 60),

    // Rutas de acceso de la web y de la API, por IP.
    'login' => (int) env('LOGIN_ATTEMPTS_PER_MINUTE', 10),
    'register' => (int) env('REGISTER_ATTEMPTS_PER_MINUTE', 5),
    'password' => (int) env('PASSWORD_ATTEMPTS_PER_MINUTE', 5),

    // Subir o quitar la foto de perfil (web y API), por IP.
    'avatar' => (int) env('AVATAR_ATTEMPTS_PER_MINUTE', 10),

    // Reenvío de invitaciones a usuarios (gestión de usuarios), por IP.
    'invitation' => (int) env('INVITATION_ATTEMPTS_PER_MINUTE', 5),

    // Subir, ordenar o quitar fotos y videos de un producto (web y API), por IP.
    'media' => (int) env('MEDIA_ATTEMPTS_PER_MINUTE', 20),

    // Catálogo público para visitantes (listado y fichas), por IP.
    'catalog' => (int) env('CATALOG_REQUESTS_PER_MINUTE', 120),

    // Búsqueda de clasificaciones del selector (se consulta mientras se escribe), por IP.
    'lookup' => (int) env('LOOKUP_REQUESTS_PER_MINUTE', 120),

    // Comprobación de salud /health (monitoreo y scripts de despliegue), por IP.
    'health' => (int) env('HEALTH_REQUESTS_PER_MINUTE', 30),

    // Actualizaciones desde la web (subir partes, verificar y avanzar los pasos de la instalación), por IP.
    'updates' => (int) env('UPDATES_REQUESTS_PER_MINUTE', 240),

];
