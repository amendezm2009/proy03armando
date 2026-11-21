<?php

namespace App\Actions;

use Throwable;

/**
 * Comprueba que una clave de firma está bien guardada y es del par cuya clave pública usa el proyecto. Nunca
 * devuelve la clave: solo si cada comprobación se cumple y, en el detalle, la huella de la clave pública.
 *
 *   1. el archivo de clave privada existe y tiene un formato válido
 *   2. está FUERA de la carpeta del proyecto (nunca debe llegar al repositorio ni al servidor)
 *   3. la clave pública del proyecto existe y es válida
 *   4. ambas tienen la misma huella
 *   5. (con $deep) se puede descifrar con la contraseña y firma algo que la clave pública verifica
 *
 * Se detiene en la primera comprobación que falla, porque las siguientes dependen de ella.
 *
 * USO: $filas = app(CheckSigningKey::class)->handle($rutaPrivada, config('release.public_key_file'));
 *      CheckSigningKey::passes($filas);
 */
class CheckSigningKey
{
    /**
     * @return list<array{ok: bool, label: string, detail: string}>
     */
    public function handle(string $privatePath, string $publicKeyFile, ?string $passphrase = null, bool $deep = false): array
    {
        $rows = [];
        $add = function (bool $ok, string $label, string $detail = '') use (&$rows): bool {
            $rows[] = ['ok' => $ok, 'label' => $label, 'detail' => $detail];

            return $ok;
        };

        $keys = app(SigningKeyFile::class);

        try {
            $privatePublic = $keys->publicKeyOf($privatePath);
        } catch (Throwable $exception) {
            $add(false, 'Archivo de clave privada válido', $exception->getMessage());

            return $rows;
        }

        $add(true, 'Archivo de clave privada válido', $keys->isEncrypted($privatePath) ? 'protegido con contraseña' : 'SIN contraseña');

        $inside = $this->isInsideProject($privatePath);

        if (! $add(! $inside, 'Clave privada fuera del proyecto', $inside ? 'está dentro de la carpeta del proyecto y debe quedar fuera' : '')) {
            return $rows;
        }

        if (! is_file($publicKeyFile)) {
            $add(false, 'Clave pública del proyecto', "no existe {$publicKeyFile}");

            return $rows;
        }

        $projectPublic = trim((string) file_get_contents($publicKeyFile));

        try {
            $projectFingerprint = SigningKeyFile::fingerprint($projectPublic);
        } catch (Throwable $exception) {
            $add(false, 'Clave pública del proyecto', $exception->getMessage());

            return $rows;
        }

        $privateFingerprint = SigningKeyFile::fingerprint($privatePublic);
        $add(true, 'Clave pública del proyecto', "huella {$projectFingerprint}");

        if (! $add($privateFingerprint === $projectFingerprint, 'La privada y la pública son del mismo par', "privada {$privateFingerprint}, pública {$projectFingerprint}")) {
            return $rows;
        }

        if ($deep) {
            $rows[] = $this->signingRoundTrip($privatePath, $passphrase, $projectPublic);
        }

        return $rows;
    }

    /**
     * @param  list<array{ok: bool, label: string, detail: string}>  $rows
     */
    public static function passes(array $rows): bool
    {
        return $rows !== [] && ! in_array(false, array_column($rows, 'ok'), true);
    }

    /**
     * @return array{ok: bool, label: string, detail: string}
     */
    private function signingRoundTrip(string $privatePath, ?string $passphrase, string $publicKey): array
    {
        $label = 'Descifra y firma correctamente';

        try {
            $secret = base64_decode(app(SigningKeyFile::class)->read($privatePath, $passphrase), true);
            $message = random_bytes(32);
            $signature = sodium_crypto_sign_detached($message, $secret);
            sodium_memzero($secret);

            return ['ok' => sodium_crypto_sign_verify_detached($signature, $message, base64_decode($publicKey, true)), 'label' => $label, 'detail' => ''];
        } catch (Throwable $exception) {
            return ['ok' => false, 'label' => $label, 'detail' => $exception->getMessage()];
        }
    }

    private function isInsideProject(string $path): bool
    {
        $normalize = fn (string $value): string => strtolower(rtrim(str_replace('\\', '/', (string) realpath($value)), '/')).'/';

        return str_starts_with($normalize(dirname($path)), $normalize(base_path()));
    }
}
