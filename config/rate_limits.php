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

];
