<?php

namespace App\Console\Commands;

use App\Actions\RunSecurityChecks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Revisa la configuración de seguridad de la instalación (ver App\Actions\RunSecurityChecks) y la muestra en una
 * tabla. En producción una revisión grave que no se cumple es FALLA y el comando termina con error (sirve para el
 * despliegue); fuera de producción solo es AVISO.
 *
 * USO: php artisan security:check            revisión normal
 *      php artisan security:check --strict   evalúa el .env actual como si fuera producción (antes de desplegar)
 *      php artisan security:check --log      además anota el resultado en el canal "security" (revisión programada)
 */
#[Signature('security:check {--strict : Evalúa la configuración actual como si fuera producción} {--log : Anota el resultado en el registro de seguridad}')]
#[Description('Revisa la configuración de seguridad (HTTPS, sesiones, contraseñas, cabeceras y registro)')]
class SecurityCheck extends Command
{
    public function handle(RunSecurityChecks $checks): int
    {
        $rows = $checks->handle(strict: $this->option('strict'));

        $this->table(['Estado', 'Revisión', 'Cómo corregirlo'], $rows->map(fn (array $row): array => array_values($row))->all());

        $failed = $rows->where('status', 'FALLA');
        $warned = $rows->where('status', 'AVISO');

        if ($this->option('log')) {
            Log::channel('security')->{$failed->isNotEmpty() ? 'warning' : 'info'}('security.check', [
                'strict' => (bool) $this->option('strict'),
                'failures' => $failed->pluck('label')->values()->all(),
                'warnings' => $warned->pluck('label')->values()->all(),
            ]);
        }

        if ($failed->isNotEmpty()) {
            $this->error("{$failed->count()} revisión(es) graves fallan en producción:");
            $this->listRows($failed);

            return self::FAILURE;
        }

        if ($warned->isNotEmpty()) {
            $this->warn("Sin fallas. {$warned->count()} aviso(s): revísalos antes de publicar en producción:");
            $this->listRows($warned);

            return self::SUCCESS;
        }

        $this->info('Todo en orden.');

        return self::SUCCESS;
    }

    /**
     * Una línea por revisión, para que el resumen final diga cuáles son y cómo corregirlas.
     *
     * @param  Collection<int, array{status: string, label: string, fix: string}>  $rows
     */
    private function listRows(Collection $rows): void
    {
        foreach ($rows as $row) {
            $this->line("  - {$row['label']}: {$row['fix']}");
        }
    }
}
