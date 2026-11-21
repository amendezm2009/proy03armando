<?php

/**
 * Reversión de emergencia de una actualización instalada desde la web, SIN usar la aplicación (que puede estar rota).
 * Solo funciona desde la línea de comandos. Devuelve las carpetas y archivos que la actualización movió a
 * storage/app/private/updates/work/rollback/ a su lugar original y quita el modo de mantenimiento.
 *
 * NO restaura la base de datos: si ya se había migrado, usa el respaldo que se hizo antes de instalar
 * (php artisan db:restore, o importa el .sql.gz de storage/app/private/backups).
 *
 * USO: php scripts/revertir-actualizacion.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit;
}

$root = dirname(__DIR__);
$work = $root.'/storage/app/private/updates/work';
$stateFile = $work.'/state.json';

if (! is_file($stateFile)) {
    fwrite(STDERR, "No hay estado de actualización en {$stateFile}.\n");

    exit(1);
}

$state = json_decode((string) file_get_contents($stateFile), true);
$journal = is_array($state) ? ($state['journal'] ?? []) : [];

if ($journal === []) {
    fwrite(STDERR, "La actualización no llegó a reemplazar el código: no hay nada que devolver.\n");

    exit(1);
}

$move = function (string $from, string $to): void {
    if (! is_dir(dirname($to)) && ! mkdir(dirname($to), 0775, true) && ! is_dir(dirname($to))) {
        throw new RuntimeException('No se pudo crear '.dirname($to));
    }

    if (! rename($from, $to)) {
        throw new RuntimeException("No se pudo mover {$from} a {$to}");
    }
};

$exists = fn (string $path): bool => file_exists($path) || is_link($path);

try {
    foreach (array_reverse($journal) as $entry) {
        $unit = str_replace('/', DIRECTORY_SEPARATOR, $entry['unit']);
        $destination = $root.DIRECTORY_SEPARATOR.$unit;
        $aside = $work.DIRECTORY_SEPARATOR.'rollback'.DIRECTORY_SEPARATOR.$unit;

        if (! $entry['removed'] && $exists($destination)) {
            $failed = $work.DIRECTORY_SEPARATOR.'failed'.DIRECTORY_SEPARATOR.$unit;

            if ($exists($failed)) {
                throw new RuntimeException("Ya existe {$failed}: bórralo antes de volver a ejecutar este script.");
            }

            $move($destination, $failed);
        }

        if ($entry['had_old'] && $exists($aside)) {
            $move($aside, $destination);
        }

        echo "Devuelto: {$entry['unit']}\n";
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FALLÓ: '.$exception->getMessage()."\n");

    exit(1);
}

$state['swapped'] = false;
$state['phase'] = 'rolled_back';
$state['error'] = ($state['error'] ?? 'Revertida a mano').' | Revertida con scripts/revertir-actualizacion.php';
file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

@unlink($root.'/storage/framework/down');

foreach (glob($root.'/bootstrap/cache/*.php') ?: [] as $cached) {
    @unlink($cached);
}

echo "\nListo. El código volvió a la versión anterior y se quitó el mantenimiento.\n";
echo "Si la base de datos ya se había migrado, restaúrala con el respaldo previo: php artisan db:restore\n";
