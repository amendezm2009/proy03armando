<?php

use App\Actions\CheckSigningKey;
use App\Actions\SetEnvValue;
use App\Actions\SigningKeyFile;
use Carbon\Carbon;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;

const SIGNING_PASSPHRASE = 'una-contraseña-bien-larga';

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proy03-keys-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->tmp);

    config([
        'release.key_strength' => 'interactive',
        'release.restrict_permissions' => false,
        'release.key_passphrase' => null,
        'release.keys_directory' => $this->tmp.'/proy_keys',
        'release.public_key_file' => $this->tmp.'/config/release-public.key',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * @return array{private: string, public: string, fingerprint: string, encrypted: bool}
 */
function signingPair(string $root, ?string $passphrase = SIGNING_PASSPHRASE, ?string $publicCopy = null): array
{
    return app(SigningKeyFile::class)->handle($root, $passphrase, $publicCopy, Carbon::create(2026, 11, 20, 20, 53), 'interactive');
}

describe('SigningKeyFile', function () {
    it('crea private/ y public/ con la fecha en el nombre y protege la privada con contraseña', function () {
        $pair = signingPair($this->tmp.'/proy_keys');

        expect(str_replace('\\', '/', $pair['private']))->toEndWith('/proy_keys/private/20261120-20-53-release-private.key')
            ->and(str_replace('\\', '/', $pair['public']))->toEndWith('/proy_keys/public/20261120-20-53-release-public.key')
            ->and($pair['fingerprint'])->toMatch('/^([0-9A-F]{4}-){3}[0-9A-F]{4}$/')
            ->and($pair['encrypted'])->toBeTrue()
            ->and(json_decode(file_get_contents($pair['private']), true))->toHaveKeys(['cipher', 'salt', 'nonce', 'public_key', 'fingerprint'])
            ->and(app(SigningKeyFile::class)->isEncrypted($pair['private']))->toBeTrue();

        $secret = base64_decode(app(SigningKeyFile::class)->read($pair['private'], SIGNING_PASSPHRASE), true);
        $public = base64_decode(trim(file_get_contents($pair['public'])), true);
        $signature = sodium_crypto_sign_detached('mensaje', $secret);

        expect(strlen($secret))->toBe(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)
            ->and(sodium_crypto_sign_verify_detached($signature, 'mensaje', $public))->toBeTrue()
            ->and(file_get_contents($pair['private']))->not->toContain(base64_encode($secret));
    });

    it('no entrega la clave con una contraseña incorrecta, vacía o con el archivo alterado', function () {
        $pair = signingPair($this->tmp.'/proy_keys');
        $keys = app(SigningKeyFile::class);

        expect(fn () => $keys->read($pair['private'], 'otra-contraseña-larga'))->toThrow(RuntimeException::class, 'Contraseña incorrecta')
            ->and(fn () => $keys->read($pair['private'], null))->toThrow(RuntimeException::class, 'protegida con contraseña')
            ->and(fn () => $keys->read($pair['private'].'.no-existe', SIGNING_PASSPHRASE))->toThrow(RuntimeException::class, 'No existe');

        $data = json_decode(file_get_contents($pair['private']), true);
        $data['cipher'] = base64_encode(random_bytes(80));
        file_put_contents($pair['private'], json_encode($data));

        expect(fn () => $keys->read($pair['private'], SIGNING_PASSPHRASE))->toThrow(RuntimeException::class, 'Contraseña incorrecta o archivo de clave dañado');
    });

    it('sin contraseña guarda la clave en Base64 y se lee sin pedirla', function () {
        $pair = signingPair($this->tmp.'/proy_keys', null);
        $keys = app(SigningKeyFile::class);

        expect($pair['encrypted'])->toBeFalse()
            ->and($keys->isEncrypted($pair['private']))->toBeFalse()
            ->and(strlen(base64_decode($keys->read($pair['private']), true)))->toBe(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES);
    });

    it('conoce la clave pública de un archivo privado sin pedir la contraseña, cifrado o no', function () {
        $keys = app(SigningKeyFile::class);
        $encrypted = signingPair($this->tmp.'/a');
        $plain = signingPair($this->tmp.'/b', null);

        expect($keys->publicKeyOf($encrypted['private']))->toBe(trim(file_get_contents($encrypted['public'])))
            ->and($keys->publicKeyOf($plain['private']))->toBe(trim(file_get_contents($plain['public'])))
            ->and(SigningKeyFile::fingerprint($keys->publicKeyOf($plain['private'])))->toBe($plain['fingerprint']);
    });

    it('exige una contraseña de al menos 12 caracteres y nunca sobrescribe archivos', function () {
        expect(fn () => signingPair($this->tmp.'/proy_keys', 'corta'))->toThrow(InvalidArgumentException::class, 'al menos 12')
            ->and(fn () => SigningKeyFile::fingerprint('no-es-una-clave'))->toThrow(InvalidArgumentException::class);

        signingPair($this->tmp.'/proy_keys');

        expect(fn () => signingPair($this->tmp.'/proy_keys'))->toThrow(RuntimeException::class, 'nunca se sobrescribe');
    });

    it('copia la clave pública adonde se pida', function () {
        $pair = signingPair($this->tmp.'/proy_keys', SIGNING_PASSPHRASE, $this->tmp.'/config/release-public.key');

        expect(trim(file_get_contents($this->tmp.'/config/release-public.key')))->toBe(trim(file_get_contents($pair['public'])));
    });
});

describe('SetEnvValue', function () {
    it('cambia solo esa variable y conserva el resto y los saltos de línea CRLF', function () {
        $env = $this->tmp.'/.env';
        file_put_contents($env, "APP_NAME=Tienda\r\n# comentario\r\nRELEASE_SIGNING_KEY_FILE=\r\nAPP_DEBUG=true\r\n");

        $result = app(SetEnvValue::class)->handle($env, 'RELEASE_SIGNING_KEY_FILE', 'D:\\claves\\firma.key');

        expect($result)->toBe(['previous' => '', 'changed' => true])
            ->and(file_get_contents($env))->toBe("APP_NAME=Tienda\r\n# comentario\r\nRELEASE_SIGNING_KEY_FILE='D:\\claves\\firma.key'\r\nAPP_DEBUG=true\r\n")
            ->and(Dotenv::parse(file_get_contents($env))['RELEASE_SIGNING_KEY_FILE'])->toBe('D:\\claves\\firma.key');
    });

    it('agrega la variable si falta, deja solo la primera si estaba repetida y no reescribe si ya está igual', function () {
        $env = $this->tmp.'/.env';
        file_put_contents($env, "APP_NAME=Tienda\n");

        app(SetEnvValue::class)->handle($env, 'RELEASE_SIGNING_KEY_FILE', '/claves/a.key');
        expect(file_get_contents($env))->toBe("APP_NAME=Tienda\nRELEASE_SIGNING_KEY_FILE='/claves/a.key'\n");

        file_put_contents($env, "RELEASE_SIGNING_KEY_FILE=uno\nOTRA=1\nRELEASE_SIGNING_KEY_FILE=dos\n");
        $result = app(SetEnvValue::class)->handle($env, 'RELEASE_SIGNING_KEY_FILE', '/claves/b.key');

        expect($result['previous'])->toBe('uno')
            ->and(file_get_contents($env))->toBe("RELEASE_SIGNING_KEY_FILE='/claves/b.key'\nOTRA=1\n")
            ->and(app(SetEnvValue::class)->handle($env, 'RELEASE_SIGNING_KEY_FILE', '/claves/b.key')['changed'])->toBeFalse();
    });

    it('rechaza nombres y valores que romperían el .env', function () {
        $env = $this->tmp.'/.env';
        file_put_contents($env, "A=1\n");
        $set = app(SetEnvValue::class);

        expect(fn () => $set->handle($env, 'nombre malo', 'x'))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $set->handle($env, 'CLAVE', "con'comilla"))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $set->handle($env, 'CLAVE', "dos\nlineas"))->toThrow(InvalidArgumentException::class)
            ->and(fn () => $set->handle($this->tmp.'/no-existe', 'CLAVE', 'x'))->toThrow(RuntimeException::class)
            ->and(file_get_contents($env))->toBe("A=1\n");
    });
});

describe('CheckSigningKey', function () {
    it('da por buena una clave fuera del proyecto que corresponde a la pública del proyecto', function () {
        $pair = signingPair($this->tmp.'/proy_keys', SIGNING_PASSPHRASE, config('release.public_key_file'));
        $check = app(CheckSigningKey::class);

        expect(CheckSigningKey::passes($check->handle($pair['private'], config('release.public_key_file'))))->toBeTrue()
            ->and(CheckSigningKey::passes($check->handle($pair['private'], config('release.public_key_file'), SIGNING_PASSPHRASE, deep: true)))->toBeTrue();
    });

    it('detecta el archivo que no existe, la pública de otro par y la contraseña incorrecta en la revisión profunda', function () {
        $pair = signingPair($this->tmp.'/proy_keys', SIGNING_PASSPHRASE, config('release.public_key_file'));
        $check = app(CheckSigningKey::class);

        $missing = $check->handle($this->tmp.'/no-existe.key', config('release.public_key_file'));
        expect(CheckSigningKey::passes($missing))->toBeFalse()->and($missing[0]['detail'])->toContain('No existe');

        $other = signingPair($this->tmp.'/otra_keys');
        $mismatch = $check->handle($pair['private'], $other['public']);
        expect(CheckSigningKey::passes($mismatch))->toBeFalse()->and(end($mismatch)['label'])->toContain('mismo par');

        $deep = $check->handle($pair['private'], config('release.public_key_file'), 'otra-contraseña-larga', deep: true);
        expect(CheckSigningKey::passes($deep))->toBeFalse()->and(end($deep)['detail'])->toContain('Contraseña incorrecta');
    });

    it('rechaza una clave guardada dentro de la carpeta del proyecto', function () {
        $root = base_path('storage/framework/testing/keys-'.bin2hex(random_bytes(4)));

        try {
            $pair = signingPair($root, SIGNING_PASSPHRASE, config('release.public_key_file'));
            $rows = app(CheckSigningKey::class)->handle($pair['private'], config('release.public_key_file'));

            expect(CheckSigningKey::passes($rows))->toBeFalse()->and(end($rows)['label'])->toContain('fuera del proyecto');
        } finally {
            File::deleteDirectory($root);
        }
    });
});

describe('release:keygen', function () {
    it('crea el par en <proyecto>_keys/private y public, copia la pública al proyecto y no muestra la clave', function () {
        config(['release.key_passphrase' => SIGNING_PASSPHRASE]);

        $this->artisan('release:keygen')
            ->expectsOutputToContain('Par de claves creado')
            ->expectsOutputToContain('Huella')
            ->expectsOutputToContain('No la envíes por correo')
            ->assertSuccessful();

        $private = glob($this->tmp.'/proy_keys/private/*-release-private.key');
        $public = glob($this->tmp.'/proy_keys/public/*-release-public.key');

        expect($private)->toHaveCount(1)
            ->and($public)->toHaveCount(1)
            ->and(basename($private[0]))->toMatch('/^\d{8}-\d{2}-\d{2}-release-private\.key$/')
            ->and(trim(file_get_contents($this->tmp.'/config/release-public.key')))->toBe(trim(file_get_contents($public[0])))
            ->and(app(SigningKeyFile::class)->isEncrypted($private[0]))->toBeTrue();
    });

    it('pide la contraseña dos veces y rechaza la que no coincide o es corta', function () {
        $this->artisan('release:keygen')
            ->expectsQuestion('Contraseña para proteger la clave privada (mínimo 12 caracteres)', 'una-contraseña-bien-larga')
            ->expectsQuestion('Repite la contraseña', 'otra-contraseña-distinta')
            ->expectsOutputToContain('no coinciden')
            ->assertFailed();

        $this->artisan('release:keygen')
            ->expectsQuestion('Contraseña para proteger la clave privada (mínimo 12 caracteres)', 'corta')
            ->expectsQuestion('Repite la contraseña', 'corta')
            ->expectsOutputToContain('al menos 12')
            ->assertFailed();

        expect(glob($this->tmp.'/proy_keys/private/*'))->toBeEmpty();
    });

    it('--no-passphrase guarda la privada sin contraseña y --no-copy no toca config/', function () {
        $this->artisan('release:keygen', ['--no-passphrase' => true, '--no-copy' => true])->assertSuccessful();

        $private = glob($this->tmp.'/proy_keys/private/*-release-private.key');

        expect(app(SigningKeyFile::class)->isEncrypted($private[0]))->toBeFalse()
            ->and(is_file($this->tmp.'/config/release-public.key'))->toBeFalse();
    });

    it('no reemplaza la clave pública del proyecto sin --force', function () {
        config(['release.key_passphrase' => SIGNING_PASSPHRASE]);
        File::ensureDirectoryExists($this->tmp.'/config');
        file_put_contents($this->tmp.'/config/release-public.key', "existente\n");

        $this->artisan('release:keygen')->expectsOutputToContain('ya tiene una clave pública')->assertFailed();

        expect(file_get_contents($this->tmp.'/config/release-public.key'))->toBe("existente\n")
            ->and(glob($this->tmp.'/proy_keys/private/*'))->toBeEmpty();

        $this->artisan('release:keygen', ['--force' => true])->assertSuccessful();
    });

    it('no acepta una carpeta de claves dentro del proyecto', function () {
        config(['release.key_passphrase' => SIGNING_PASSPHRASE]);
        $inside = base_path('storage/framework/testing/keys-'.bin2hex(random_bytes(4)));

        $this->artisan('release:keygen', ['directory' => $inside])->expectsOutputToContain('FUERA de la carpeta del proyecto')->assertFailed();

        expect(is_dir($inside))->toBeFalse();
    });
});

describe('release:keycheck', function () {
    it('revisa la clave configurada y falla si el par no coincide o no hay clave', function () {
        $pair = signingPair($this->tmp.'/proy_keys', SIGNING_PASSPHRASE, config('release.public_key_file'));

        config(['release.signing_key_file' => $pair['private']]);
        $this->artisan('release:keycheck')->expectsOutputToContain('Clave de firma en orden')->assertSuccessful();

        config(['release.key_passphrase' => SIGNING_PASSPHRASE]);
        $this->artisan('release:keycheck', ['--deep' => true])->expectsOutputToContain('Descifra y firma correctamente')->assertSuccessful();

        config(['release.key_passphrase' => 'otra-contraseña-larga']);
        $this->artisan('release:keycheck', ['--deep' => true])->expectsOutputToContain('no pasa la revisión')->assertFailed();

        config(['release.signing_key_file' => null]);
        $this->artisan('release:keycheck')->expectsOutputToContain('No hay clave de firma configurada')->assertFailed();
    });
});

describe('release:use-key', function () {
    beforeEach(function () {
        $this->project = $this->tmp.'/proyecto';
        File::ensureDirectoryExists($this->project);
        file_put_contents($this->project.'/.env', "APP_NAME=Tienda\nAPP_KEY=base64:secreto-que-no-debe-salir\nRELEASE_SIGNING_KEY_FILE=\n");
        $this->app->setBasePath($this->project);

        config(['release.public_key_file' => $this->project.'/config/release-public.key']);
        $this->pair = signingPair($this->tmp.'/proyecto_keys', SIGNING_PASSPHRASE, $this->project.'/config/release-public.key');
    });

    it('registra la clave en el .env, guarda la copia fuera del proyecto y no muestra otros valores', function () {
        $this->artisan('release:use-key', ['path' => $this->pair['private'], '--yes' => true])
            ->doesntExpectOutputToContain('secreto-que-no-debe-salir')
            ->expectsOutputToContain('El .env ya apunta a la clave de firma')
            ->assertSuccessful();

        $values = Dotenv::parse(file_get_contents($this->project.'/.env'));
        $backups = glob($this->tmp.'/proyecto_keys/env-backups/*-env.bak');

        expect(realpath($values['RELEASE_SIGNING_KEY_FILE']))->toBe(realpath($this->pair['private']))
            ->and($values['APP_NAME'])->toBe('Tienda')
            ->and($backups)->toHaveCount(1)
            ->and(file_get_contents($backups[0]))->toContain('RELEASE_SIGNING_KEY_FILE=')->not->toContain("'");
    });

    it('--dry-run y un "no" a la confirmación dejan el .env intacto', function () {
        $before = file_get_contents($this->project.'/.env');

        $this->artisan('release:use-key', ['path' => $this->pair['private'], '--dry-run' => true])->assertSuccessful();
        expect(file_get_contents($this->project.'/.env'))->toBe($before);

        $this->artisan('release:use-key', ['path' => $this->pair['private']])
            ->expectsConfirmation('¿Aplicar el cambio?', 'no')
            ->expectsOutputToContain('Cancelado')
            ->assertFailed();

        expect(file_get_contents($this->project.'/.env'))->toBe($before)
            ->and(glob($this->tmp.'/proyecto_keys/env-backups/*'))->toBeEmpty();
    });

    it('si el .env ya apunta a esa clave no cambia nada', function () {
        $this->artisan('release:use-key', ['path' => $this->pair['private'], '--yes' => true])->assertSuccessful();
        $this->artisan('release:use-key', ['path' => $this->pair['private'], '--yes' => true])->expectsOutputToContain('nada que cambiar')->assertSuccessful();

        expect(glob($this->tmp.'/proyecto_keys/env-backups/*'))->toHaveCount(1);
    });

    it('rechaza una clave que no es del par del proyecto o que está dentro del proyecto, sin tocar el .env', function () {
        $before = file_get_contents($this->project.'/.env');
        $other = signingPair($this->tmp.'/otras_keys');

        $this->artisan('release:use-key', ['path' => $other['private'], '--yes' => true])->expectsOutputToContain('mismo par')->assertFailed();

        $inside = signingPair($this->project.'/dentro_keys', SIGNING_PASSPHRASE);
        $this->artisan('release:use-key', ['path' => $inside['private'], '--yes' => true])->expectsOutputToContain('fuera del proyecto')->assertFailed();

        expect(file_get_contents($this->project.'/.env'))->toBe($before);
    });
});
