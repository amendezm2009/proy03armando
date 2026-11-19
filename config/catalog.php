<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Moneda del catálogo
    |--------------------------------------------------------------------------
    |
    | Código ISO 4217 y configuración regional con que se muestran los precios
    | (Illuminate\Support\Number::currency). Solo cambia la presentación: en la
    | base de datos los precios son decimales sin moneda.
    |
    */

    'currency' => env('CATALOG_CURRENCY', 'MXN'),

    'currency_locale' => env('CATALOG_CURRENCY_LOCALE', 'es_MX'),

];
