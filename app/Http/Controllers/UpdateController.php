<?php

namespace App\Http\Controllers;

use App\Actions\BackupDatabase;
use App\Actions\BumpVersion;
use App\Actions\ConfirmPassword;
use App\Actions\InspectUpdate;
use App\Actions\RecordSecurityEvent;
use App\Actions\RunUpdate;
use App\Actions\StoreUpdatePart;
use App\Actions\UpdateStorage;
use App\Http\Requests\PasswordConfirmationRequest;
use App\Http\Requests\UpdateStartRequest;
use App\Http\Requests\UpdateUploadRequest;
use App\Http\Requests\UpdateVersionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

/**
 * Actualizaciones de la aplicación desde la web, solo para quien tiene el permiso updates.manage (administración).
 *
 * Flujo: subir las partes de un paquete firmado (de una en una) → verificarlo (InspectUpdate) → instalarlo paso a
 * paso (RunUpdate) con la contraseña del administrador → si algo falla, la instalación se revierte sola.
 *
 * Protecciones: permiso en la ruta; nada se instala sin que la firma, los hashes y la compatibilidad pasen; la
 * contraseña se pide al EMPEZAR y al REVERTIR (con el límite de intentos de ConfirmPassword); solo quien empezó la
 * instalación puede avanzarla; todas las respuestas son JSON sin caché; y cada paso importante queda en el registro
 * de seguridad (update.part_uploaded, update.started, update.finished, update.rolled_back, update.reverted,
 * update.discarded). La subida solo admite archivos con nombre de paquete (StoreUpdatePart).
 */
class UpdateController extends Controller
{
    public function index(RunUpdate $runner, UpdateStorage $storage, StoreUpdatePart $parts, InspectUpdate $inspect, BackupDatabase $backups): View
    {
        $installed = $storage->installed();

        return view('updates.index', [
            'version' => $storage->installedVersion(),
            'build' => $storage->installedBuild(),
            'installedAt' => $installed['installed_at'] ?? null,
            'maintenance' => app()->isDownForMaintenance(),
            'status' => $runner->status(),
            'packages' => array_map(fn (string $version): array => [
                'version' => $version,
                'files' => $parts->present($version),
                'parts' => $inspect->progress($version),
            ], $storage->inboxVersions()),
            'history' => $storage->history(),
            'backups' => array_slice($backups->list(), 0, 5),
        ]);
    }

    public function upload(UpdateUploadRequest $request, StoreUpdatePart $parts, RecordSecurityEvent $record): JsonResponse
    {
        try {
            $present = $parts->handle($request->file('file'), $request->validated('version'));
        } catch (InvalidArgumentException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        $record->handle('update.part_uploaded', ['version' => $request->validated('version'), 'file' => $request->file('file')->getClientOriginalName()], $request);

        return $this->json(['present' => $present]);
    }

    public function inspect(UpdateVersionRequest $request, InspectUpdate $inspect): JsonResponse
    {
        return $this->json($inspect->handle($request->validated('version')));
    }

    public function start(UpdateStartRequest $request, ConfirmPassword $confirm, RunUpdate $runner, RecordSecurityEvent $record): JsonResponse
    {
        $confirm->handle($request, $request->validated('current_password'));

        try {
            $status = $runner->start($request->validated('version'), (int) $request->user()->getAuthIdentifier());
        } catch (RuntimeException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        $record->handle('update.started', ['version' => $status['version']], $request);

        return $this->json($status);
    }

    public function step(Request $request, RunUpdate $runner, RecordSecurityEvent $record): JsonResponse
    {
        $status = $runner->status();

        if ($status === null || $status['finished']) {
            return $this->json(['message' => 'No hay una instalación en curso.'], 409);
        }

        abort_unless($status['user_id'] === (int) $request->user()->getAuthIdentifier(), 403);

        $result = $runner->step();

        if ($result['finished']) {
            $record->handle($result['ok'] ? 'update.finished' : 'update.rolled_back', ['version' => $result['version'], 'phase' => $result['phase']], $request);
        }

        return $this->json($result);
    }

    public function revert(PasswordConfirmationRequest $request, ConfirmPassword $confirm, RunUpdate $runner, RecordSecurityEvent $record): JsonResponse
    {
        $confirm->handle($request, $request->validated('current_password'));

        try {
            $status = $runner->revert('Revertida por el administrador.');
        } catch (RuntimeException $exception) {
            return $this->json(['message' => $exception->getMessage()], 422);
        }

        $record->handle('update.reverted', ['version' => $status['version']], $request);

        return $this->json($status);
    }

    public function discard(string $version, UpdateStorage $storage, RunUpdate $runner, RecordSecurityEvent $record, Request $request): JsonResponse
    {
        if (! BumpVersion::isValid($version)) {
            return $this->json(['message' => 'La versión debe tener el formato MAYOR.MENOR.PARCHE.'], 422);
        }

        $status = $runner->status();

        if ($status !== null && ! $status['finished'] && $status['version'] === $version) {
            return $this->json(['message' => 'Esa versión se está instalando: revierte la instalación antes de descartarla.'], 422);
        }

        $storage->discardInbox($version);
        $record->handle('update.discarded', ['version' => $version], $request);

        return $this->json(['discarded' => $version]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }
}
