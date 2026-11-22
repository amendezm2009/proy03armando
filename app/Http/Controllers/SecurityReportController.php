<?php

namespace App\Http\Controllers;

use App\Actions\ConfirmPassword;
use App\Actions\RecordSecurityEvent;
use App\Actions\RunSecurityChecks;
use App\Http\Requests\PasswordConfirmationRequest;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Informe de seguridad de la configuración, solo para quien tiene el permiso security.view (administración).
 * Es de solo lectura y no ejecuta comandos: repite las revisiones de `php artisan security:check`.
 *
 * Protecciones: permiso en la ruta, contraseña en CADA visita (con el límite de intentos de ConfirmPassword), el
 * informe se entrega en la respuesta del POST y no se guarda en la sesión, no muestra valores de configuración
 * (solo si están bien puestos), la página no se guarda en el navegador y cada consulta queda en el registro de seguridad.
 */
class SecurityReportController extends Controller
{
    public function create(): View
    {
        return view('security.report');
    }

    public function store(PasswordConfirmationRequest $request, ConfirmPassword $confirm, RunSecurityChecks $checks, RecordSecurityEvent $record): Response
    {
        $confirm->handle($request, $request->validated('current_password'));

        $strict = $request->boolean('strict');

        $record->handle('security.report_viewed', ['strict' => $strict], $request);

        // El middleware "no-store" del grupo web solo actúa en GET: esta respuesta es de un POST, así que se
        // indica aquí para que el navegador no la guarde (botón "atrás", historial).
        return response()
            ->view('security.report', ['rows' => $checks->handle($strict), 'strict' => $strict])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }
}
