<?php

namespace App\Actions;

use DateTimeInterface;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Crea, protege y lee los archivos de claves con los que se firman las versiones (ver config/release.php).
 *
 * Al generar un par se escriben dos archivos, con la fecha en el nombre, dentro de la carpeta de claves:
 *   {carpeta}/private/20261120-20-53-release-private.key   clave privada (cifrada con contraseña si se da una)
 *   {carpeta}/public/20261120-20-53-release-public.key     clave pública (Base64; es la que se sube al repositorio)
 *
 * La clave privada cifrada es un JSON con la clave sellada con sodium_crypto_secretbox (XSalsa20-Poly1305) bajo una
 * clave derivada de la contraseña con Argon2id; ahí mismo van la clave pública y su huella, que no son secretas, para
 * poder comprobar de qué par es el archivo sin pedir la contraseña. Sin contraseña, el archivo es solo la clave en
 * Base64 (formato de las primeras versiones, que sigue leyéndose).
 *
 * La huella (16 hex en grupos de 4, p. ej. 3FA9-0C1B-77D2-E5A4) identifica una clave pública: sirve para comprobar a
 * simple vista que dos lugares hablan del mismo par.
 *
 * USO: $par = app(SigningKeyFile::class)->handle($carpeta, $contraseña);
 *      $secretaBase64 = app(SigningKeyFile::class)->read($par['private'], $contraseña);
 */
class SigningKeyFile
{
    public const MIN_PASSPHRASE_LENGTH = 12;

    /** Costo de derivar la clave de la contraseña: [operaciones, memoria]. "moderate" tarda cerca de un segundo. */
    private const STRENGTH = [
        'interactive' => [SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE],
        'moderate' => [SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE],
    ];

    /**
     * Genera un par de claves nuevo. Nunca sobrescribe archivos existentes.
     *
     * @param  string|null  $publicCopy  Ruta adicional donde copiar la clave pública (p. ej. config/release-public.key).
     * @return array{private: string, public: string, fingerprint: string, encrypted: bool}
     */
    public function handle(string $rootDir, ?string $passphrase, ?string $publicCopy = null, ?DateTimeInterface $at = null, string $strength = 'moderate'): array
    {
        if (! extension_loaded('sodium')) {
            throw new RuntimeException('Falta la extensión PHP «sodium».');
        }

        if ($passphrase !== null && mb_strlen($passphrase) < self::MIN_PASSPHRASE_LENGTH) {
            throw new InvalidArgumentException('La contraseña de la clave debe tener al menos '.self::MIN_PASSPHRASE_LENGTH.' caracteres.');
        }

        $stamp = ($at ?? now())->format('Ymd-H-i');
        $privatePath = $rootDir.DIRECTORY_SEPARATOR.'private'.DIRECTORY_SEPARATOR."{$stamp}-release-private.key";
        $publicPath = $rootDir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR."{$stamp}-release-public.key";

        foreach ([$privatePath, $publicPath] as $path) {
            if (File::exists($path)) {
                throw new RuntimeException("Ya existe {$path}: un archivo de claves nunca se sobrescribe (espera un minuto para generar otro par).");
            }
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = base64_encode(sodium_crypto_sign_publickey($pair));

        File::ensureDirectoryExists(dirname($privatePath));
        File::ensureDirectoryExists(dirname($publicPath));

        File::put($privatePath, $passphrase === null ? base64_encode($secret)."\n" : $this->sealed($secret, $public, $passphrase, $strength));
        File::put($publicPath, $public."\n");

        if ($publicCopy !== null) {
            File::ensureDirectoryExists(dirname($publicCopy));
            File::put($publicCopy, $public."\n");
        }

        sodium_memzero($secret);

        return ['private' => $privatePath, 'public' => $publicPath, 'fingerprint' => self::fingerprint($public), 'encrypted' => $passphrase !== null];
    }

    /**
     * Devuelve la clave privada (Base64), descifrándola si hace falta.
     *
     * @throws RuntimeException Si falta el archivo, está dañado, pide contraseña y no se dio o es incorrecta.
     */
    public function read(string $path, ?string $passphrase = null): string
    {
        $contents = $this->contents($path);

        if (! $this->isSealed($contents)) {
            return $this->plainSecret($contents);
        }

        if ($passphrase === null || $passphrase === '') {
            throw new RuntimeException('La clave de firma está protegida con contraseña y no se indicó ninguna.');
        }

        $data = $this->sealedData($contents);
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $data['salt'], $data['opslimit'], $data['memlimit'], SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $secret = sodium_crypto_secretbox_open($data['cipher'], $data['nonce'], $key);
        sodium_memzero($key);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Contraseña incorrecta o archivo de clave dañado.');
        }

        $base64 = base64_encode($secret);
        sodium_memzero($secret);

        return $base64;
    }

    public function isEncrypted(string $path): bool
    {
        return $this->isSealed($this->contents($path));
    }

    /**
     * La clave pública (Base64) del par al que pertenece un archivo de clave privada, sin pedir la contraseña.
     */
    public function publicKeyOf(string $path): string
    {
        $contents = $this->contents($path);

        if ($this->isSealed($contents)) {
            return $this->sealedData($contents)['public_key'];
        }

        $secret = base64_decode($this->plainSecret($contents), true);

        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    }

    /**
     * Huella de una clave pública en Base64: 16 hex mayúsculas en grupos de 4.
     */
    public static function fingerprint(string $publicKeyBase64): string
    {
        $raw = base64_decode(trim($publicKeyBase64), true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('La clave pública no es válida.');
        }

        return strtoupper(implode('-', str_split(substr(hash('sha256', $raw), 0, 16), 4)));
    }

    private function sealed(string $secret, string $publicKey, string $passphrase, string $strength): string
    {
        [$opslimit, $memlimit] = self::STRENGTH[$strength] ?? throw new InvalidArgumentException("Costo de derivación «{$strength}» desconocido.");

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, $opslimit, $memlimit, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $cipher = sodium_crypto_secretbox($secret, $nonce, $key);
        sodium_memzero($key);

        return json_encode([
            'format' => 1,
            'encrypted' => true,
            'kdf' => 'argon2id13',
            'opslimit' => $opslimit,
            'memlimit' => $memlimit,
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'cipher' => base64_encode($cipher),
            'public_key' => $publicKey,
            'fingerprint' => self::fingerprint($publicKey),
            'created_at' => gmdate('c'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    private function contents(string $path): string
    {
        if (! is_file($path)) {
            throw new RuntimeException("No existe el archivo de clave {$path}.");
        }

        return trim((string) file_get_contents($path));
    }

    private function isSealed(string $contents): bool
    {
        return str_starts_with($contents, '{');
    }

    private function plainSecret(string $contents): string
    {
        $raw = base64_decode($contents, true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('El archivo no contiene una clave privada Ed25519 válida.');
        }

        return $contents;
    }

    /**
     * @return array{salt: string, nonce: string, cipher: string, opslimit: int, memlimit: int, public_key: string}
     */
    private function sealedData(string $contents): array
    {
        $data = json_decode($contents, true);

        if (! is_array($data) || ($data['format'] ?? null) !== 1 || ($data['kdf'] ?? null) !== 'argon2id13') {
            throw new RuntimeException('El archivo de clave protegida tiene un formato desconocido.');
        }

        $salt = base64_decode((string) ($data['salt'] ?? ''), true);
        $nonce = base64_decode((string) ($data['nonce'] ?? ''), true);
        $cipher = base64_decode((string) ($data['cipher'] ?? ''), true);

        if ($salt === false || strlen($salt) !== SODIUM_CRYPTO_PWHASH_SALTBYTES
            || $nonce === false || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            || $cipher === false || $cipher === ''
            || ! is_int($data['opslimit'] ?? null) || ! is_int($data['memlimit'] ?? null)
            || ! is_string($data['public_key'] ?? null)) {
            throw new RuntimeException('El archivo de clave protegida está dañado.');
        }

        return ['salt' => $salt, 'nonce' => $nonce, 'cipher' => $cipher, 'opslimit' => $data['opslimit'], 'memlimit' => $data['memlimit'], 'public_key' => $data['public_key']];
    }
}
