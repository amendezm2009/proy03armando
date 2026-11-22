<?php

namespace App\Actions;

use App\Models\User;
use App\RoleName;
use Database\Seeders\ClassificationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;
use ZipArchive;

/**
 * Deja listo, en una carpeta de tu equipo, todo lo que hace falta para instalar la aplicación POR PRIMERA VEZ en un
 * hosting sin SSH (donde /admin/actualizaciones aún no existe porque la app no está instalada):
 *
 *   {salida}/sitio/                       el contenido del paquete ya verificado y descomprimido: lo que se sube por FTP
 *   {salida}/env-produccion.txt           plantilla del .env de producción (APP_KEY y HEALTH_TOKEN nuevos; lo demás, a rellenar)
 *   {salida}/base-de-datos/               base de datos inicial (roles, permisos y un administrador) en .sql.gz y .sql
 *   {salida}/LEEME.txt                    los pasos para subirlo
 *
 * El paquete se verifica entero (firma, hashes, rutas) antes de extraer nada. Junto al sitio se escribe
 * storage/app/private/updates/installed.json (versión, build y SHA-256 de cada archivo) para que la PRIMERA actualización
 * desde la web ya sepa qué archivos traía la versión instalada. La base de datos se construye en una base TEMPORAL
 * (migraciones + seeders + administrador con contraseña aleatoria), se vuelca y se borra: nunca se toca tu base de
 * desarrollo. La contraseña del administrador se devuelve para mostrarse una sola vez; no se guarda en ningún archivo.
 *
 * USO: app(PrepareFirstInstall::class)->handle(base_path('releases/v1.0.0'), base_path('releases/instalar-v1.0.0'), ['admin_email' => 'yo@example.com']);
 */
class PrepareFirstInstall
{
    /**
     * @param  array{admin_name?: string, admin_email?: string|null, database?: bool, classifications?: bool}  $options
     * @return array{version: string, build: int, files: int, site: string, env: string, readme: string, database: ?string, database_sql: ?string, admin_email: ?string, admin_password: ?string}
     *
     * @throws RuntimeException|InvalidArgumentException Con el motivo en español si algo no cuadra.
     */
    public function handle(string $package, string $output, array $options = []): array
    {
        $withDatabase = $options['database'] ?? true;
        $adminEmail = $options['admin_email'] ?? null;

        if ($withDatabase && (blank($adminEmail) || ! filter_var($adminEmail, FILTER_VALIDATE_EMAIL))) {
            throw new InvalidArgumentException('Indica el correo del administrador con un formato válido (--admin-email).');
        }

        $manifest = app(VerifyReleasePackage::class)->handle($package, VerifyReleasePackage::trustedKeys());

        if (is_dir($output) && (glob(rtrim($output, '/\\').DIRECTORY_SEPARATOR.'*') ?: []) !== []) {
            throw new RuntimeException("Ya existe {$output} con contenido: no se sobrescribe (renómbrala o bórrala).");
        }

        File::ensureDirectoryExists($output);

        try {
            $site = $output.DIRECTORY_SEPARATOR.'sitio';
            $inventory = $this->extract($package, $manifest, $site);
            $this->writeInventory($site, $manifest, $inventory);

            $env = $output.DIRECTORY_SEPARATOR.'env-produccion.txt';
            $this->envTemplate($site, $env);

            $database = null;
            $databaseSql = null;
            $password = null;

            if ($withDatabase) {
                [$database, $databaseSql, $password] = $this->buildDatabase(
                    $output.DIRECTORY_SEPARATOR.'base-de-datos',
                    (string) ($options['admin_name'] ?? 'Administrador'),
                    (string) $adminEmail,
                    (bool) ($options['classifications'] ?? false),
                );
            }

            $readme = $output.DIRECTORY_SEPARATOR.'LEEME.txt';
            file_put_contents($readme, $this->readme($manifest, $withDatabase));
        } catch (Throwable $exception) {
            File::deleteDirectory($output);

            throw $exception;
        }

        return [
            'version' => (string) $manifest['version'],
            'build' => (int) ($manifest['build'] ?? 1),
            'files' => count($inventory),
            'site' => $site,
            'env' => $env,
            'readme' => $readme,
            'database' => $database,
            'database_sql' => $databaseSql,
            'admin_email' => $withDatabase ? $adminEmail : null,
            'admin_password' => $password,
        ];
    }

    /**
     * Une las partes, extrae el ZIP (ya verificado) y devuelve el inventario RELEASE.json, que se quita del sitio.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string> ruta => SHA-256
     */
    private function extract(string $package, array $manifest, string $site): array
    {
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'inst');
        $output = fopen($zipPath, 'wb');

        foreach ($manifest['parts'] as $part) {
            $input = fopen($package.DIRECTORY_SEPARATOR.basename((string) $part['file']), 'rb');
            stream_copy_to_stream($input, $output);
            fclose($input);
        }

        fclose($output);

        try {
            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('El ZIP unido no se puede abrir.');
            }

            File::ensureDirectoryExists($site);

            if (! $zip->extractTo($site)) {
                $zip->close();

                throw new RuntimeException('No se pudo extraer el paquete.');
            }

            $zip->close();
        } finally {
            @unlink($zipPath);
        }

        $inventoryFile = $site.DIRECTORY_SEPARATOR.'RELEASE.json';
        $inventory = json_decode((string) @file_get_contents($inventoryFile), true)['files'] ?? null;

        if (! is_array($inventory) || $inventory === []) {
            throw new RuntimeException('El paquete no trae su inventario de archivos (RELEASE.json).');
        }

        @unlink($inventoryFile);

        return $inventory;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $inventory
     */
    private function writeInventory(string $site, array $manifest, array $inventory): void
    {
        $directory = $site.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR.'updates';
        UpdateStorage::protect($directory);

        file_put_contents($directory.DIRECTORY_SEPARATOR.'installed.json', json_encode([
            'version' => $manifest['version'],
            'build' => (int) ($manifest['build'] ?? 1),
            'installed_at' => gmdate('c'),
            'files' => $inventory,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function envTemplate(string $site, string $target): void
    {
        $example = $site.DIRECTORY_SEPARATOR.'.env.example';

        if (! is_file($example)) {
            throw new RuntimeException('El paquete no trae .env.example: no se puede preparar la plantilla del .env.');
        }

        $contents = str_replace("\r\n", "\n", (string) file_get_contents($example));

        $values = [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'https://CAMBIAR-por-tu-dominio',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'LOG_LEVEL' => 'warning',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'CAMBIAR',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'CAMBIAR',
            'DB_USERNAME' => 'CAMBIAR',
            'DB_PASSWORD' => 'CAMBIAR',
            'SESSION_SECURE_COOKIE' => 'true',
            'SESSION_SAME_SITE' => 'lax',
            'HEALTH_TOKEN' => bin2hex(random_bytes(24)),
            'RELEASE_SIGNING_KEY_FILE' => '',
        ];

        foreach ($values as $key => $value) {
            $contents = $this->setEnvLine($contents, $key, $value);
        }

        $header = "# PLANTILLA del .env de PRODUCCIÓN. Rellena cada «CAMBIAR», súbela al servidor con el nombre «.env»\n"
            ."# y BÓRRALA de tu equipo después: contiene secretos (APP_KEY y HEALTH_TOKEN). Nunca la subas a git.\n"
            ."# La clave privada de firma NO va en el servidor: RELEASE_SIGNING_KEY_FILE debe quedar vacío.\n\n";

        file_put_contents($target, $header.$contents);
    }

    /**
     * Pone «CLAVE=valor» en la primera línea que la defina (aunque esté comentada) o la agrega al final.
     */
    private function setEnvLine(string $contents, string $key, string $value): string
    {
        $replaced = 0;
        $result = preg_replace_callback(
            '/^[ \t]*#?[ \t]*'.preg_quote($key, '/').'=.*$/m',
            fn (): string => "{$key}={$value}",
            $contents,
            1,
            $replaced,
        );

        return $replaced === 1 ? (string) $result : rtrim($contents)."\n{$key}={$value}\n";
    }

    /**
     * Construye la base inicial en una base TEMPORAL, la vuelca a $directory y la borra.
     *
     * @return array{string, string, string} Ruta del .sql.gz, del .sql y contraseña del administrador.
     */
    private function buildDatabase(string $directory, string $adminName, string $adminEmail, bool $classifications): array
    {
        $default = (string) config('database.default');
        $config = (array) config("database.connections.{$default}");
        $driver = (string) ($config['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("La base de datos inicial solo se puede preparar con MySQL/MariaDB o SQLite (la conexión usa «{$driver}»).");
        }

        $temporary = 'install_tmp';

        if ($driver === 'sqlite') {
            $file = (string) tempnam(sys_get_temp_dir(), 'instdb');
            $config['database'] = $file;
            $cleanup = function () use ($file): void {
                @unlink($file);
            };
        } else {
            $name = 'proy03_instalar_'.bin2hex(random_bytes(4));
            DB::connection($default)->statement("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $config['database'] = $name;
            $cleanup = function () use ($default, $name): void {
                DB::connection($default)->statement("DROP DATABASE IF EXISTS `{$name}`");
            };
        }

        config(["database.connections.{$temporary}" => $config]);
        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'instdump-'.bin2hex(random_bytes(4));

        try {
            Artisan::call('migrate', ['--database' => $temporary, '--force' => true]);
            $password = $this->seed($temporary, $adminName, $adminEmail, $classifications);
            $backup = app(BackupDatabase::class)->handle($temporary, $work, 'instalacion');

            File::ensureDirectoryExists($directory);
            $gz = $directory.DIRECTORY_SEPARATOR.'base-de-datos-inicial.sql'.(str_ends_with($backup['file'], '.gz') ? '.gz' : '');
            $sql = $directory.DIRECTORY_SEPARATOR.'base-de-datos-inicial.sql';
            copy($backup['file'], $gz);

            if ($gz !== $sql) {
                file_put_contents($sql, (string) gzdecode((string) file_get_contents($gz)));
            }

            return [$gz, $sql, $password];
        } finally {
            DB::purge($temporary);
            $cleanup();
            File::deleteDirectory($work);
        }
    }

    private function seed(string $connection, string $adminName, string $adminEmail, bool $classifications): string
    {
        $previous = (string) config('database.default');
        DB::setDefaultConnection($connection);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            Artisan::call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

            if ($classifications) {
                Artisan::call('db:seed', ['--class' => ClassificationSeeder::class, '--force' => true]);
            }

            $password = Str::password(20, symbols: false);
            $user = User::create(['name' => $adminName, 'email' => $adminEmail, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole(RoleName::Admin);

            return $password;
        } finally {
            DB::setDefaultConnection($previous);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function readme(array $manifest, bool $withDatabase): string
    {
        $build = (int) ($manifest['build'] ?? 1);
        $database = $withDatabase
            ? "3. BASE DE DATOS: en el panel del hosting crea una base de datos MySQL y anota servidor, nombre, usuario y contraseña.\n"
                ."   Abre phpMyAdmin, elige esa base y usa «Importar» con base-de-datos\\base-de-datos-inicial.sql.gz\n"
                ."   (o el .sql si el hosting no acepta .gz o limita el tamaño). Ya trae roles, permisos y el administrador.\n"
            : "3. BASE DE DATOS: (no se preparó: usaste --no-database).\n";

        return <<<TEXT
Primera instalación de la versión {$manifest['version']} (build {$build}) en un hosting sin SSH
=====================================================================================================

1. SITIO: sube por FTP TODO el contenido de la carpeta «sitio» a la carpeta del sitio en el hosting
   (incluye vendor, public/build y el esqueleto de storage; no hace falta node_modules).
   Da permiso de escritura (755 o 775) a storage y bootstrap/cache.
2. .env: abre env-produccion.txt, rellena cada «CAMBIAR» (dirección del sitio y datos de la base de datos del paso 3)
   y súbelo a la carpeta del sitio con el nombre «.env». Después BÓRRALO de tu equipo: contiene secretos.
{$database}4. SERVIDOR WEB: debe servir la carpeta «public». Si la raíz del proyecto es la carpeta pública del hosting,
   añade en la raíz un .htaccess que mande todo a public/ (ver el README, «Distribución del hosting»).
5. COMPRUEBA: abre https://tu-dominio/health (debe decir {"status":"ok"}) y https://tu-dominio/login.
   Entra como administrador y cambia la contraseña inicial. Revisa /admin/seguridad con «Evaluar como producción».
6. Desde ahí, cada versión nueva se sube en /admin/actualizaciones (partes + manifest.json + manifest.json.sig).

NUNCA subas al servidor la clave privada de firma ni tu .env de desarrollo.
TEXT;
    }
}
