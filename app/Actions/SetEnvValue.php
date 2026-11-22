<?php

namespace App\Actions;

use InvalidArgumentException;
use RuntimeException;

/**
 * Cambia (o agrega) UNA variable de un archivo .env sin tocar el resto: conserva los demás valores, los comentarios,
 * el orden y el tipo de salto de línea (CRLF o LF). Si la variable aparece repetida deja solo la primera, que es la
 * que Laravel leería. El valor se escribe entre comillas SIMPLES: entre dobles, el \ de una ruta de Windows se
 * interpreta como secuencia de escape y rompe todo el .env.
 *
 * Devuelve el valor anterior (sin comillas) para que quien llama pueda mostrar el cambio, y si algo cambió.
 * No hace copia de seguridad ni pide confirmación: eso lo decide quien llama (ver php artisan release:use-key).
 *
 * USO: app(SetEnvValue::class)->handle(base_path('.env'), 'RELEASE_SIGNING_KEY_FILE', 'C:\claves\firma.key');
 */
class SetEnvValue
{
    /**
     * @return array{previous: string|null, changed: bool}
     */
    public function handle(string $envPath, string $key, string $value): array
    {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException("«{$key}» no es un nombre de variable de entorno válido.");
        }

        if (preg_match("/['\\r\\n\\0]/", $value) === 1) {
            throw new InvalidArgumentException('El valor no puede contener comillas simples, saltos de línea ni caracteres nulos.');
        }

        if (! is_file($envPath)) {
            throw new RuntimeException("No existe {$envPath}.");
        }

        $original = (string) file_get_contents($envPath);
        $eol = str_contains($original, "\r\n") ? "\r\n" : "\n";
        $lines = preg_split('/\r\n|\n/', $original);

        if (end($lines) === '') {
            array_pop($lines);
        }

        $replacement = "{$key}='{$value}'";
        $previous = null;
        $found = false;
        $result = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=\s*(.*)$/', $line, $match) !== 1) {
                $result[] = $line;

                continue;
            }

            if (! $found) {
                $found = true;
                $previous = trim(trim($match[1]), '\'"');
                $result[] = $replacement;
            }
        }

        if (! $found) {
            $result[] = $replacement;
        }

        $updated = implode($eol, $result).$eol;

        if ($updated === $original) {
            return ['previous' => $previous, 'changed' => false];
        }

        file_put_contents($envPath, $updated, LOCK_EX);

        return ['previous' => $previous, 'changed' => true];
    }
}
