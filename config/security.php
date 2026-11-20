<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Política de seguridad de contenido (CSP)
    |--------------------------------------------------------------------------
    |
    | "enforce" la aplica (el navegador bloquea lo que no cumple), "report-only" solo avisa en la consola del
    | navegador (útil para probar un cambio sin romper páginas) y "off" no envía la cabecera. Los scripts en línea
    | llevan un "nonce" distinto en cada respuesta: un script inyectado por un atacante no lo tiene y no se ejecuta.
    | App\Http\Middleware\SecurityHeaders arma la política; ver también php artisan security:check.
    |
    */

    'csp' => env('SECURITY_CSP', 'enforce'),

    /*
    |--------------------------------------------------------------------------
    | Strict-Transport-Security (HSTS)
    |--------------------------------------------------------------------------
    |
    | Solo se envía en respuestas HTTPS: obliga al navegador a usar siempre HTTPS con este dominio durante un año.
    | Actívalo cuando el sitio ya funcione bien por HTTPS (un error de certificado deja de poder saltarse).
    |
    */

    'hsts' => (bool) env('SECURITY_HSTS', true),

    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

];
