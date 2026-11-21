<?php

namespace App\Actions;

use InvalidArgumentException;

/**
 * Calcula la siguiente versión semántica (MAYOR.MENOR.PARCHE) a partir de la actual.
 *
 * USO: app(BumpVersion::class)->handle('1.4.2', 'minor') devuelve '1.5.0'.
 *
 * Tipos: patch (corrección de errores), minor (función nueva compatible) y major (cambio que rompe compatibilidad).
 */
class BumpVersion
{
    public const PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/';

    public function handle(string $current, string $type): string
    {
        if (! self::isValid($current)) {
            throw new InvalidArgumentException("La versión actual «{$current}» no tiene el formato MAYOR.MENOR.PARCHE.");
        }

        [$major, $minor, $patch] = array_map('intval', explode('.', $current));

        return match ($type) {
            'major' => ($major + 1).'.0.0',
            'minor' => "{$major}.".($minor + 1).'.0',
            'patch' => "{$major}.{$minor}.".($patch + 1),
            default => throw new InvalidArgumentException("Tipo de cambio «{$type}» desconocido: usa patch, minor o major."),
        };
    }

    public static function isValid(string $version): bool
    {
        return preg_match(self::PATTERN, $version) === 1;
    }
}
