<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Subida de la foto de perfil (web y API). Cada persona cambia solo la suya.
 *
 * Solo JPG, PNG o WebP (el SVG queda fuera: puede llevar scripts), de hasta 2 MB y entre 64 y 3000 px
 * por lado (el límite superior evita imágenes que agoten la memoria al decodificarlas). La regla
 * "image" comprueba el contenido real del archivo, no su extensión. App\Actions\SaveAvatar vuelve a
 * decodificarla y la reduce, así que un archivo corrupto también se rechaza ahí.
 */
class AvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // cualquier persona con sesión cambia su propia foto
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048',
                'dimensions:min_width=64,min_height=64,max_width=3000,max_height=3000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['avatar' => 'foto de perfil'];
    }
}
