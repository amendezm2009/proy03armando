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

    /*
    |--------------------------------------------------------------------------
    | Mantenimiento automático por errores de configuración
    |--------------------------------------------------------------------------
    |
    | En producción, mientras alguna revisión grave de php artisan security:check falle (FALLA), el público y los
    | clientes ven la página de mantenimiento y solo los administradores entran al sitio para corregirlo y probarlo.
    | Al corregir el error, el sitio vuelve solo. Ver App\Http\Middleware\RestrictPublicOnConfigurationErrors.
    | Ponlo en false solo si prefieres que el sitio siga abierto con la configuración a medias.
    |
    */

    'block_on_errors' => (bool) env('SECURITY_BLOCK_ON_ERRORS', true),

];
