<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;

/**
 * Guarda la foto de perfil de un usuario.
 *
 * - Decodifica la imagen de verdad (un archivo que no lo sea se rechaza aunque tenga extensión
 *   de imagen), aplica la orientación EXIF, recorta al centro en cuadrado, la reduce a SIZE px y
 *   la codifica a WebP. Al volver a codificarla se pierden todos los metadatos (ubicación GPS,
 *   cámara, fecha) y cualquier contenido ajeno al píxel.
 * - El nombre del archivo lo genera el servidor (40 caracteres aleatorios): nunca el que envía el usuario.
 * - Se guarda en el disco privado (no hace falta "storage:link"); lo sirve App\Http\Controllers\AvatarFileController.
 * - La foto anterior se borra solo cuando la nueva ya quedó guardada.
 *
 * USO: app(SaveAvatar::class)->handle($user, $request->file('avatar'));
 */
class SaveAvatar
{
    public const string DISK = 'local';

    public const int SIZE = 256;

    private const int QUALITY = 85;

    /**
     * @throws ValidationException
     */
    public function handle(User $user, UploadedFile $file): User
    {
        $contents = $this->process($file);

        $path = 'avatars/'.Str::random(40).'.webp';
        Storage::disk(self::DISK)->put($path, $contents);

        $previous = $user->avatar_path;
        $user->forceFill(['avatar_path' => $path])->save();

        if ($previous !== null) {
            Storage::disk(self::DISK)->delete($previous);
        }

        Log::info('Foto de perfil actualizada.', ['user_id' => $user->id]);

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function process(UploadedFile $file): string
    {
        try {
            // Se decodifica desde la memoria y no desde la ruta: algunos hostings (p. ej. InfinityFree) dejan la
            // carpeta temporal sin permiso de lectura y decodePath() la rechaza aunque el archivo sí se pueda leer.
            return (string) ImageManager::usingDriver(Driver::class)
                ->decodeBinary((string) file_get_contents($file->getRealPath()))
                ->orient()
                ->cover(self::SIZE, self::SIZE)
                ->encodeUsingFormat(Format::WEBP, quality: self::QUALITY);
        } catch (ImageException) {
            throw ValidationException::withMessages(['avatar' => __('validation.image', ['attribute' => 'foto de perfil'])]);
        }
    }
}
