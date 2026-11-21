<?php

namespace App\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Guarda un archivo de un paquete de actualización (los que genera scripts/release.ps1) en inbox/v{versión}/.
 * Se sube de uno en uno porque los hostings limitan el tamaño de cada petición (por eso el paquete viene en partes).
 *
 * Solo acepta tres nombres: manifest.json, manifest.json.sig y app-vX.Y.Z.zip.NNN (el X.Y.Z debe coincidir con la
 * versión indicada). Cualquier otro nombre se rechaza, así que nada se escribe fuera de esa carpeta. Aquí no se
 * confía en el contenido: la firma y los hashes los comprueba InspectUpdate antes de instalar nada.
 *
 * USO: app(StoreUpdatePart::class)->handle($archivoSubido, '1.2.0');
 */
class StoreUpdatePart
{
    private const NAME = '/^(?:manifest\.json|manifest\.json\.sig|app-v(\d+\.\d+\.\d+)\.zip\.\d{3})$/';

    public function __construct(private readonly UpdateStorage $storage) {}

    /**
     * @return list<string> Archivos que hay ahora en la carpeta de esa versión.
     *
     * @throws InvalidArgumentException Con el motivo en español si el archivo no es aceptable.
     */
    public function handle(UploadedFile $file, string $version): array
    {
        if (! BumpVersion::isValid($version)) {
            throw new InvalidArgumentException('La versión debe tener el formato MAYOR.MENOR.PARCHE.');
        }

        $name = $file->getClientOriginalName();

        if (! preg_match(self::NAME, $name, $found)) {
            throw new InvalidArgumentException("«{$name}» no es un archivo de actualización: solo se aceptan manifest.json, manifest.json.sig y app-vX.Y.Z.zip.NNN.");
        }

        if (($found[1] ?? $version) !== $version) {
            throw new InvalidArgumentException("«{$name}» es de la versión {$found[1]}, no de la {$version}.");
        }

        $limit = match (true) {
            $name === 'manifest.json' => 2 * 1024 * 1024,
            $name === 'manifest.json.sig' => 1024,
            default => (int) config('release.upload_max_kb') * 1024,
        };

        if ($file->getSize() === false || $file->getSize() > $limit) {
            throw new InvalidArgumentException("«{$name}» es demasiado grande para ese tipo de archivo.");
        }

        UpdateStorage::protect($this->storage->directory);

        $directory = $this->storage->versionInbox($version);
        File::ensureDirectoryExists($directory);
        $file->move($directory, $name);

        return $this->present($version);
    }

    /**
     * @return list<string>
     */
    public function present(string $version): array
    {
        $files = array_map('basename', glob($this->storage->versionInbox($version).DIRECTORY_SEPARATOR.'*') ?: []);
        sort($files);

        return $files;
    }
}
