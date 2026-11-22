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

    /*
    |--------------------------------------------------------------------------
    | Tamaño de página
    |--------------------------------------------------------------------------
    |
    | Opciones del selector "Mostrar" en las listas de los paneles y en el catálogo público, y el valor con que
    | empiezan: 5 en cada lista de los paneles y 10 en el catálogo (dos filas de cinco productos).
    |
    */

    'page_sizes' => [5, 10, 20, 50],

    'dashboard_page_size' => 5,

    'catalog_page_size' => 10,

    /*
    |--------------------------------------------------------------------------
    | Multimedia de los productos
    |--------------------------------------------------------------------------
    |
    | Reglas de negocio de las fotos y videos que se adjuntan a un producto
    | (App\Rules\ProductMediaFile y App\Actions\AddProductMedia).
    |
    */

    'media' => [
        // Archivos (fotos o videos) por producto.
        'max_files' => 9,
        // Peso máximo de una foto, en KB (inclusive).
        'image_max_kb' => 2048,
        // Megapíxeles máximos de una foto: decodificarla gasta memoria en proporción a sus píxeles.
        'image_max_megapixels' => 40,
        // Lado mayor al que se reduce la foto guardada y lado de la miniatura cuadrada.
        'image_max_side' => 1600,
        'thumbnail_side' => 320,
        // Un video debe pesar MENOS de esto (bytes) y durar como máximo estos segundos.
        'video_max_bytes' => 10 * 1024 * 1024,
        'video_max_seconds' => 300,
    ],

];
