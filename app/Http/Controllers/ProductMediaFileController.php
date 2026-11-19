<?php

namespace App\Http\Controllers;

use App\Actions\AddProductMedia;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Entrega las fotos, miniaturas y videos de los productos desde el disco privado, sin "php artisan storage:link".
 *
 * Es una ruta pública y sin sesión (bootstrap/app.php), como la de las fotos de perfil: el navegador debe poder
 * guardar los archivos y mostrarlos en el catálogo. Los nombres son aleatorios (40 caracteres) y no se
 * adivinan, la ruta solo acepta ese formato con extensión webp, mp4 o webm (no hay forma de salir de la
 * carpeta) y cada archivo nuevo tiene un nombre nuevo, por eso se puede guardar un año ("immutable").
 * Se responde con un archivo (BinaryFileResponse) y no con un flujo para que los videos admitan peticiones
 * "Range": sin ellas el navegador no puede adelantar ni retroceder.
 */
class ProductMediaFileController extends Controller
{
    private const array CONTENT_TYPES = ['webp' => 'image/webp', 'mp4' => 'video/mp4', 'webm' => 'video/webm'];

    public function __invoke(string $filename): BinaryFileResponse
    {
        $path = AddProductMedia::DIRECTORY."/{$filename}";
        $disk = Storage::disk(AddProductMedia::DISK);

        abort_unless($disk->exists($path), 404);

        return response()->file($disk->path($path), [
            'Content-Type' => self::CONTENT_TYPES[pathinfo($filename, PATHINFO_EXTENSION)],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            // Si alguien abre el archivo directamente, no puede ejecutar nada ni cargar recursos. No afecta a
            // <img> ni <video>, que solo usan el contenido.
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
