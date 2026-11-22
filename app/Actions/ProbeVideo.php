<?php

namespace App\Actions;

use getID3;
use Throwable;

/**
 * Lee la duración de un video (getID3: MP4 y WebM) sin necesitar ffmpeg. Se resuelve desde el contenedor
 * para que las pruebas puedan sustituirlo.
 *
 * USO: app(ProbeVideo::class)->durationSeconds($file->getRealPath());
 */
class ProbeVideo
{
    /**
     * Duración en segundos, o null si el archivo no se pudo leer como video.
     */
    public function durationSeconds(string $path): ?float
    {
        try {
            $seconds = (new getID3)->analyze($path)['playtime_seconds'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_numeric($seconds) && $seconds > 0 ? (float) $seconds : null;
    }
}
