<?php

namespace App\Actions;

use Illuminate\Support\Facades\File;

/**
 * Dónde viven los archivos de las actualizaciones desde la web. Todo cuelga de config('release.updates_directory')
 * (por omisión storage/app/private/updates, fuera de la parte pública):
 *
 *   inbox/v1.2.0/        partes y manifiesto subidos (manifest.json, manifest.json.sig, app-v1.2.0.zip.001...)
 *   work/                lo que usa la instalación en curso: state.json, package.zip, staging/, rollback/, failed/
 *   installed.json       versión instalada, su build y el SHA-256 de cada archivo (para saber qué sobra al actualizar)
 *
 * $root es la carpeta de la aplicación (por omisión, base_path()); las pruebas usan carpetas temporales.
 *
 * USO: $storage = app(UpdateStorage::class);  $storage->installedVersion();
 */
class UpdateStorage
{
    public readonly string $root;

    public readonly string $directory;

    public function __construct(?string $root = null, ?string $directory = null)
    {
        $this->root = rtrim($root ?? base_path(), '/\\');
        $this->directory = rtrim($directory ?? (string) config('release.updates_directory'), '/\\');
    }

    /**
     * Crea la carpeta (si falta) y le deja un .htaccess que prohíbe servirla por la web. Si el hosting usa la raíz del
     * proyecto como carpeta pública, sin esto los respaldos y paquetes subidos se podrían descargar por su dirección.
     * No sustituye a sacar la carpeta de la parte pública, pero cubre el caso habitual de los hostings compartidos.
     */
    public static function protect(string $directory): void
    {
        File::ensureDirectoryExists($directory);

        $file = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'.htaccess';

        if (! is_file($file)) {
            file_put_contents($file, "# Carpeta privada: nunca se sirve por la web.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
    }

    public function inbox(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'inbox';
    }

    public function versionInbox(string $version): string
    {
        return $this->inbox().DIRECTORY_SEPARATOR."v{$version}";
    }

    public function work(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'work';
    }

    public function installedFile(): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.'installed.json';
    }

    public function installedVersion(): string
    {
        $file = $this->root.DIRECTORY_SEPARATOR.'VERSION';

        return is_file($file) ? trim((string) file_get_contents($file)) : '0.0.0';
    }

    /**
     * @return array{version?: string, build?: int, installed_at?: string, files?: array<string, string>}
     */
    public function installed(): array
    {
        $data = is_file($this->installedFile()) ? json_decode((string) file_get_contents($this->installedFile()), true) : null;

        return is_array($data) ? $data : [];
    }

    /**
     * El build de la versión instalada: el que anotó la última instalación desde la web, o 1 si se instaló de otro modo.
     */
    public function installedBuild(): int
    {
        $installed = $this->installed();

        return ($installed['version'] ?? null) === $this->installedVersion() ? max(1, (int) ($installed['build'] ?? 1)) : 1;
    }

    /**
     * Versiones con archivos esperando en inbox/, de la más reciente a la más antigua.
     *
     * @return list<string>
     */
    public function inboxVersions(): array
    {
        $versions = [];

        foreach (glob($this->inbox().DIRECTORY_SEPARATOR.'v*', GLOB_ONLYDIR) ?: [] as $directory) {
            $version = substr(basename($directory), 1);

            if (BumpVersion::isValid($version)) {
                $versions[] = $version;
            }
        }

        usort($versions, fn (string $a, string $b): int => version_compare($b, $a));

        return $versions;
    }

    /**
     * Las últimas líneas del historial de instalaciones (history.log), de la más reciente a la más antigua.
     *
     * @return list<string>
     */
    public function history(int $limit = 10): array
    {
        $file = $this->directory.DIRECTORY_SEPARATOR.'history.log';
        $lines = is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];

        return array_slice(array_reverse($lines), 0, $limit);
    }

    public function discardInbox(string $version): void
    {
        if (BumpVersion::isValid($version)) {
            File::deleteDirectory($this->versionInbox($version));
        }
    }
}
