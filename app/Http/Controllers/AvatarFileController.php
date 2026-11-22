<?php

namespace App\Http\Controllers;

use App\Actions\SaveAvatar;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entrega la foto de perfil desde el disco privado, sin depender de "php artisan storage:link".
 *
 * Es una ruta pública y sin sesión (bootstrap/app.php): una foto es una imagen que se pide muchas
 * veces y el navegador debe poder guardarla. El nombre es aleatorio y no se adivina, la ruta solo acepta
 * 40 caracteres alfanuméricos y ".webp" (no hay forma de salir de la carpeta), y cada foto nueva
 * tiene un nombre nuevo, por eso se puede guardar un año ("immutable").
 */
class AvatarFileController extends Controller
{
    public function __invoke(string $filename): StreamedResponse
    {
        $path = "avatars/{$filename}";
        $disk = Storage::disk(SaveAvatar::DISK);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $filename, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
