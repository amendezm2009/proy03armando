<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Comprobación de salud para monitoreo y para los scripts de despliegue (ruta /health).
 *
 * Cualquiera recibe solo {"status": "ok"} (200) o {"status": "error"} (503). Quien envíe la cabecera X-Health-Token
 * con el valor de HEALTH_TOKEN (config/release.php) recibe además la versión instalada, el entorno y el detalle de
 * cada comprobación: así un script verifica que la versión esperada quedó instalada sin que el público pueda
 * averiguar qué versión corre (dato útil para un atacante). Sin HEALTH_TOKEN nadie recibe esos datos.
 */
class HealthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $checks = ['database' => $this->databaseResponds()];
        $healthy = ! in_array(false, $checks, true);

        $body = ['status' => $healthy ? 'ok' : 'error'];

        if ($this->hasValidToken($request)) {
            $body += ['version' => config('app.version'), 'environment' => app()->environment(), 'checks' => $checks];
        }

        return response()->json($body, $healthy ? 200 : 503)->header('Cache-Control', 'no-store, private');
    }

    private function hasValidToken(Request $request): bool
    {
        $expected = (string) config('release.health_token');

        return $expected !== '' && hash_equals($expected, (string) $request->header('X-Health-Token'));
    }

    private function databaseResponds(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
