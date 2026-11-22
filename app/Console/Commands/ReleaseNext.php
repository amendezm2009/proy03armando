<?php

namespace App\Console\Commands;

use App\Actions\BumpVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Calcula la siguiente versión a partir del archivo VERSION y la imprime sola en una línea (para que
 * scripts/release.ps1 la capture). Con --write, además la guarda en VERSION.
 *
 * USO: php artisan release:next minor           muestra 1.5.0 si la actual es 1.4.2
 *      php artisan release:next patch --write   y la guarda en VERSION
 */
#[Signature('release:next {type : patch (corrección), minor (función nueva compatible) o major (cambio que rompe compatibilidad)} {--write : Guarda la versión nueva en el archivo VERSION}')]
#[Description('Calcula (y opcionalmente guarda) la siguiente versión de la aplicación')]
class ReleaseNext extends Command
{
    public function handle(BumpVersion $bump): int
    {
        $path = base_path('VERSION');

        try {
            $next = $bump->handle(is_file($path) ? trim((string) file_get_contents($path)) : '0.0.0', (string) $this->argument('type'));
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('write')) {
            file_put_contents($path, $next."\n");
        }

        $this->line($next);

        return self::SUCCESS;
    }
}
