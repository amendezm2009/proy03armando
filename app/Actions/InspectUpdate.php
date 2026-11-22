<?php

namespace App\Actions;

use RuntimeException;

/**
 * Decide si un paquete subido (inbox/v{versión}/) se puede instalar, sin tocar nada. Comprueba:
 *
 *   1. la cadena de confianza completa (App\Actions\VerifyReleasePackage): firma con una clave de confianza, hash de
 *      cada parte, del ZIP y de cada archivo, sin rutas peligrosas
 *   2. que sea de la versión que se dice y más nueva que la instalada (nunca se baja de versión; la misma versión solo
 *      se acepta con un build mayor)
 *   3. que el PHP del servidor cumpla requires_php del paquete y exista la extensión zip
 *   4. que haya espacio libre (el triple del paquete: partes, ZIP unido y archivos extraídos)
 *
 * Devuelve "problems" (impiden instalar) y "warnings" (se muestran pero no impiden), y un resumen del manifiesto.
 *
 * USO: $informe = app(InspectUpdate::class)->handle('1.2.0');   if ($informe['ok']) { ... }
 */
class InspectUpdate
{
    public function __construct(private readonly UpdateStorage $storage) {}

    /**
     * @return array{version: string, ok: bool, problems: list<string>, warnings: list<string>, summary: array<string, mixed>|null, installed: array{version: string, build: int}, parts: array{expected: int, present: int}}
     */
    public function handle(string $version): array
    {
        $problems = [];
        $warnings = [];
        $summary = null;
        $installed = ['version' => $this->storage->installedVersion(), 'build' => $this->storage->installedBuild()];
        $directory = $this->storage->versionInbox($version);

        if (! BumpVersion::isValid($version)) {
            $problems[] = 'La versión debe tener el formato MAYOR.MENOR.PARCHE.';
        } elseif (! is_dir($directory)) {
            $problems[] = "No hay archivos subidos para la versión {$version}.";
        } else {
            try {
                $manifest = app(VerifyReleasePackage::class)->handle($directory, VerifyReleasePackage::trustedKeys());
                $summary = $this->summary($manifest);

                [$problems, $warnings] = $this->compatibility($manifest, $version, $installed);
            } catch (RuntimeException $exception) {
                $problems[] = $exception->getMessage();
            }
        }

        return [
            'version' => $version,
            'ok' => $problems === [],
            'problems' => $problems,
            'warnings' => $warnings,
            'summary' => $summary,
            'installed' => $installed,
            'parts' => $this->partCounts($directory),
        ];
    }

    /**
     * Partes subidas frente a las que anuncia el manifiesto (para mostrar el avance de la subida).
     *
     * @return array{expected: int, present: int}
     */
    public function progress(string $version): array
    {
        return $this->partCounts($this->storage->versionInbox($version));
    }

    /**
     * ¿Cumple $php (por omisión, el PHP en uso) una restricción de composer.json como «^8.3», «>=8.2 <8.5», «~8.3.1»,
     * «8.3.*» o alternativas con «||»? Una restricción que no se entiende no bloquea.
     */
    public static function phpSatisfies(string $constraint, string $php = PHP_VERSION): bool
    {
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
            $matches = true;

            foreach (preg_split('/[\s,]+/', trim($alternative)) ?: [] as $token) {
                if ($token !== '' && ! self::tokenMatches($token, $php)) {
                    $matches = false;

                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private static function tokenMatches(string $token, string $php): bool
    {
        if (! preg_match('/^(\^|~|>=|<=|>|<|==|=)?v?(\d+(?:\.\d+){0,2})(\.\*)?$/', $token, $found)) {
            return true;
        }

        $operator = $found[1] ?: '=';
        $segments = array_map('intval', explode('.', $found[2]));
        $given = count($segments);
        [$major, $minor, $patch] = array_pad($segments, 3, 0);
        $base = "{$major}.{$minor}.{$patch}";

        if (($found[3] ?? '') === '.*') {
            return array_slice(array_map('intval', explode('.', $php)), 0, $given) === $segments;
        }

        return match ($operator) {
            '^' => version_compare($php, $base, '>=') && version_compare($php, match (true) {
                $major > 0 => ($major + 1).'.0.0',
                $minor > 0 => '0.'.($minor + 1).'.0',
                default => '0.0.'.($patch + 1),
            }, '<'),
            '~' => version_compare($php, $base, '>=') && version_compare($php, $given >= 3 ? "{$major}.".($minor + 1).'.0' : ($major + 1).'.0.0', '<'),
            '>=' => version_compare($php, $base, '>='),
            '<=' => version_compare($php, $base, '<='),
            '>' => version_compare($php, $base, '>'),
            '<' => version_compare($php, $base, '<'),
            default => $given === 3 ? version_compare($php, $base, '==') : array_slice(array_map('intval', explode('.', $php)), 0, $given) === $segments,
        };
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array{version: string, build: int}  $installed
     * @return array{list<string>, list<string>}
     */
    private function compatibility(array $manifest, string $version, array $installed): array
    {
        $problems = [];
        $warnings = [];

        if ($manifest['version'] !== $version) {
            $problems[] = "Los archivos subidos son de la versión {$manifest['version']}, no de la {$version}.";
        }

        $comparison = version_compare((string) $manifest['version'], $installed['version']);
        $build = (int) ($manifest['build'] ?? 1);

        if ($comparison < 0) {
            $problems[] = "No se permite instalar una versión anterior: está instalada la {$installed['version']} y el paquete es la {$manifest['version']}.";
        } elseif ($comparison === 0 && $build <= $installed['build']) {
            $problems[] = "La versión {$installed['version']} (build {$installed['build']}) ya está instalada; el paquete es el build {$build}.";
        }

        if (($manifest['previous_version'] ?? null) !== null && $manifest['previous_version'] !== $installed['version'] && $comparison > 0) {
            $warnings[] = "El paquete reemplaza a la {$manifest['previous_version']} y está instalada la {$installed['version']}: se saltan versiones intermedias (las migraciones se aplican todas).";
        }

        if (($manifest['requires_php'] ?? null) && ! self::phpSatisfies((string) $manifest['requires_php'])) {
            $problems[] = "El paquete necesita PHP {$manifest['requires_php']} y el servidor tiene ".PHP_VERSION.'.';
        }

        if (! extension_loaded('zip')) {
            $problems[] = 'Falta la extensión PHP «zip» para extraer el paquete.';
        }

        $free = is_dir($this->storage->directory) ? @disk_free_space($this->storage->directory) : false;
        $needed = 3 * (int) ($manifest['package']['size'] ?? 0);

        if ($free !== false && $free < $needed) {
            $problems[] = 'No hay espacio libre suficiente: se necesitan '.number_format($needed / 1048576, 0).' MiB y quedan '.number_format($free / 1048576, 0).' MiB.';
        }

        return [$problems, $warnings];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function summary(array $manifest): array
    {
        return [
            'version' => $manifest['version'],
            'build' => (int) ($manifest['build'] ?? 1),
            'previous_version' => $manifest['previous_version'] ?? null,
            'signing_key' => $manifest['verified_with'] ?? null,
            'requires_php' => $manifest['requires_php'] ?? null,
            'built_at' => $manifest['built_at'] ?? null,
            'commit' => $manifest['commit'] ?? null,
            'migrations' => $manifest['migrations'] ?? [],
            'files' => (int) ($manifest['package']['files'] ?? 0),
            'size' => (int) $manifest['package']['size'],
        ];
    }

    /**
     * Partes que anuncia el manifiesto y partes que ya están subidas (el manifiesto aún no está verificado: es solo
     * para mostrar el avance de la subida).
     *
     * @return array{expected: int, present: int}
     */
    private function partCounts(string $directory): array
    {
        $manifest = is_file($directory.DIRECTORY_SEPARATOR.'manifest.json') ? json_decode((string) file_get_contents($directory.DIRECTORY_SEPARATOR.'manifest.json'), true) : null;
        $names = is_array($manifest) && is_array($manifest['parts'] ?? null) ? array_column($manifest['parts'], 'file') : [];

        return [
            'expected' => count($names),
            'present' => count(array_filter($names, fn ($name): bool => is_string($name) && basename($name) === $name && is_file($directory.DIRECTORY_SEPARATOR.$name))),
        ];
    }
}
