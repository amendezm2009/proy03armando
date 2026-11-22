<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * scripts/deploy-servidor.sh se prueba de verdad: con un repositorio remoto, una «máquina de desarrollo» que publica
 * versiones y un «servidor» (clon) donde se ejecuta el script, con un artisan y un composer de mentira que solo
 * anotan qué se les pidió. Se omiten si en este equipo no hay bash y git.
 */
function deployBash(): ?string
{
    $candidates = array_filter([getenv('DEPLOY_TEST_BASH') ?: null, 'C:\Program Files\Git\bin\bash.exe', '/bin/bash', '/usr/bin/bash']);

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $git = new Process(['git', '--version']);
            $git->run();

            return $git->isSuccessful() ? $candidate : null;
        }
    }

    return null;
}

function slashes(string $path): string
{
    return str_replace('\\', '/', $path);
}

/**
 * @param  list<string>  $command
 * @param  array<string, string>  $env
 * @return array{int, string}
 */
function runCommand(array $command, string $cwd, array $env = [], ?string $input = null): array
{
    $process = new Process($command, $cwd, [
        'GIT_AUTHOR_NAME' => 'Prueba', 'GIT_AUTHOR_EMAIL' => 'prueba@example.test',
        'GIT_COMMITTER_NAME' => 'Prueba', 'GIT_COMMITTER_EMAIL' => 'prueba@example.test',
        ...$env,
    ]);
    $process->setInput($input ?? '');
    $process->setTimeout(120);
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

function gitIn(string $directory, string ...$arguments): string
{
    [$code, $output] = runCommand(['git', '-c', 'core.autocrlf=false', '-c', 'commit.gpgsign=false', ...$arguments], $directory);

    expect($code)->toBe(0, 'git '.implode(' ', $arguments).": {$output}");

    return trim($output);
}

/**
 * Publica en «desarrollo» una versión nueva en la rama production y la sube al remoto.
 *
 * @param  array<string, string|null>  $files  Archivos a escribir (null = borrar).
 */
function publishVersion(string $dev, string $version, array $files = []): void
{
    $files = ['VERSION' => $version."\n", "notas-{$version}.txt" => "cambios de {$version}\n", ...$files];

    foreach ($files as $path => $contents) {
        if ($contents === null) {
            @unlink($dev.'/'.$path);

            continue;
        }

        File::ensureDirectoryExists(dirname($dev.'/'.$path));
        file_put_contents($dev.'/'.$path, $contents);
    }

    gitIn($dev, 'add', '-A');
    gitIn($dev, 'commit', '-q', '-m', "release: v{$version}");
    gitIn($dev, 'push', '-q', 'origin', 'production');
}

beforeEach(function () {
    $this->bash = deployBash();

    if ($this->bash === null) {
        $this->markTestSkipped('Hace falta bash y git para probar el script de despliegue.');
    }

    $this->tmp = slashes(sys_get_temp_dir()).'/proy03-deploy-'.bin2hex(random_bytes(4));
    $this->remote = $this->tmp.'/remote.git';
    $this->dev = $this->tmp.'/dev';
    $this->server = $this->tmp.'/server';
    $this->log = $this->tmp.'/deploy.log';
    File::ensureDirectoryExists($this->tmp.'/bin');
    File::ensureDirectoryExists($this->dev);

    file_put_contents($this->tmp.'/bin/composer', "#!/usr/bin/env bash\necho \"composer \$*\" >> \"\$DEPLOY_LOG\"\n");

    gitIn($this->tmp, 'init', '-q', '--bare', 'remote.git');
    gitIn($this->dev, 'init', '-q');
    gitIn($this->dev, 'checkout', '-q', '-b', 'production');
    gitIn($this->dev, 'remote', 'add', 'origin', $this->remote);

    file_put_contents($this->dev.'/.gitattributes', "* text eol=lf\n");
    file_put_contents($this->dev.'/.gitignore', ".env\n.fail-migrate\nstorage/\n");
    file_put_contents($this->dev.'/artisan', <<<'PHP'
<?php
$command = $argv[1] ?? '';
file_put_contents(getenv('DEPLOY_LOG'), implode(' ', array_slice($argv, 1))."\n", FILE_APPEND);

switch ($command) {
    case 'list':
        if (! getenv('DEPLOY_STUB_NO_BACKUP')) {
            echo "db:backup\ndb:restore\nmigrate\n";
        }
        break;
    case 'db:backup':
        $file = __DIR__.'/storage/backups/respaldo.sql.gz';
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, 'x');
        echo "  Archivo ........ {$file}\n";
        break;
    case 'migrate':
        if (is_file(__DIR__.'/.fail-migrate')) {
            fwrite(STDERR, "migración rota\n");
            exit(1);
        }
        break;
    case 'migrate:status':
        echo "  Ran  2026_01_01_000000_x\n";
        break;
}
PHP);
    File::ensureDirectoryExists($this->dev.'/public/build');
    file_put_contents($this->dev.'/public/build/manifest.json', '{}');
    File::ensureDirectoryExists($this->dev.'/scripts');
    copy(base_path('scripts/deploy-servidor.sh'), $this->dev.'/scripts/deploy-servidor.sh');

    publishVersion($this->dev, '1.0.0');
    gitIn($this->tmp, 'clone', '-q', '-b', 'production', $this->remote, 'server');
    file_put_contents($this->server.'/.env', 'APP_KEY=secreto');

    $this->deploy = function (array $arguments = [], array $env = [], ?string $input = null): array {
        return runCommand([$this->bash, $this->server.'/scripts/deploy-servidor.sh', ...$arguments], $this->server, [
            'PHP_BIN' => slashes(PHP_BINARY),
            'COMPOSER_BIN' => $this->tmp.'/bin/composer',
            'DEPLOY_LOG' => $this->log,
            'PATH' => $this->tmp.'/bin'.PATH_SEPARATOR.(getenv('PATH') ?: ''),
            ...$env,
        ], $input);
    };

    $this->logged = fn (): array => is_file($this->log) ? array_values(array_filter(array_map('trim', file($this->log)))) : [];
});

afterEach(function () {
    if (isset($this->tmp)) {
        File::deleteDirectory($this->tmp);
    }
});

describe('deploy-servidor.sh', function () {
    it('--dry-run muestra qué actualizaría sin cambiar nada', function () {
        publishVersion($this->dev, '1.1.0');
        $before = gitIn($this->server, 'rev-parse', 'HEAD');

        [$code, $output] = ($this->deploy)(['--dry-run']);

        expect($code)->toBe(0, $output)
            ->and($output)->toContain('1.0.0 -> 1.1.0')
            ->and($output)->toContain('Commits que llegan')
            ->and($output)->toContain('Simulación')
            ->and(gitIn($this->server, 'rev-parse', 'HEAD'))->toBe($before)
            ->and(($this->logged)())->not->toContain('down --retry=60');
    });

    it('siempre muestra el plan y pregunta: con «c» continúa y con Enter o «s» sale sin cambiar nada', function () {
        publishVersion($this->dev, '1.1.0');
        $before = gitIn($this->server, 'rev-parse', 'HEAD');

        foreach (["\n", "s\n", "cualquier cosa\n", ''] as $answer) {
            [$code, $output] = ($this->deploy)([], [], $answer);

            expect($code)->toBe(0, $output)
                ->and($output)->toContain('Plan: lo que se va a hacer (de la versión 1.0.0 a la 1.1.0)')
                ->and($output)->toContain('git merge --ff-only origin/production')
                ->and($output)->toContain('¿Continuar con la actualización a la versión 1.1.0?')
                ->and($output)->toContain('Saliste: no se cambió nada')
                ->and(gitIn($this->server, 'rev-parse', 'HEAD'))->toBe($before);
        }

        expect(($this->logged)())->not->toContain('down --retry=60');

        [$code, $output] = ($this->deploy)([], [], "c\n");

        expect($code)->toBe(0, $output)
            ->and($output)->toContain('Plan: lo que se va a hacer')
            ->and(trim(file_get_contents($this->server.'/VERSION')))->toBe('1.1.0');
    });

    it('con --yes muestra el plan igualmente y solo omite la pregunta', function () {
        publishVersion($this->dev, '1.1.0');

        [$code, $output] = ($this->deploy)(['--yes']);

        expect($code)->toBe(0, $output)
            ->and($output)->toContain('Plan: lo que se va a hacer')
            ->and($output)->toContain('--yes: se continúa sin preguntar')
            ->and($output)->not->toContain('[c = continuar / s = salir]');
    });

    it('actualiza en orden: mantenimiento, respaldo, git, composer, migraciones, cachés y fin del mantenimiento', function () {
        $old = gitIn($this->server, 'rev-parse', 'HEAD');
        publishVersion($this->dev, '1.1.0');

        [$code, $output] = ($this->deploy)(['--yes']);
        $log = ($this->logged)();
        $position = fn (string $line): int|false => array_search($line, $log, true);

        expect($code)->toBe(0, $output)
            ->and(trim(file_get_contents($this->server.'/VERSION')))->toBe('1.1.0')
            ->and(gitIn($this->server, 'rev-parse', 'HEAD'))->toBe(gitIn($this->dev, 'rev-parse', 'HEAD'))
            ->and(is_file($this->server.'/notas-1.1.0.txt'))->toBeTrue()
            ->and($position('down --retry=60'))->not->toBeFalse()
            ->and($position('down --retry=60'))->toBeLessThan($position('db:backup --no-ansi --label=antes-de-v1.1.0'))
            ->and($position('db:backup --no-ansi --label=antes-de-v1.1.0'))->toBeLessThan($position('composer install --no-dev --optimize-autoloader --no-interaction'))
            ->and($position('composer install --no-dev --optimize-autoloader --no-interaction'))->toBeLessThan($position('migrate --force'))
            ->and($position('migrate --force'))->toBeLessThan($position('config:cache'))
            ->and($position('config:cache'))->toBeLessThan($position('up'))
            ->and(end($log))->toBe('up')
            ->and(file_get_contents($this->server.'/storage/app/private/deploy/previous-commit'))->toContain($old)
            ->and(file_get_contents($this->server.'/storage/app/private/deploy/history.log'))->toContain('1.0.0 -> 1.1.0  OK')
            ->and(file_get_contents($this->server.'/.env'))->toBe('APP_KEY=secreto');
    });

    it('si la migración falla restaura la base de datos, vuelve al commit anterior y quita el mantenimiento', function () {
        $old = gitIn($this->server, 'rev-parse', 'HEAD');
        publishVersion($this->dev, '1.1.0');
        file_put_contents($this->server.'/.fail-migrate', '');

        [$code, $output] = ($this->deploy)(['--yes']);
        $log = array_map('slashes', ($this->logged)());
        $restore = 'db:restore '.$this->server.'/storage/backups/respaldo.sql.gz --yes';

        expect($code)->toBe(1, $output)
            ->and($output)->toContain('Falló: php artisan migrate --force')
            ->and(gitIn($this->server, 'rev-parse', 'HEAD'))->toBe($old)
            ->and(trim(file_get_contents($this->server.'/VERSION')))->toBe('1.0.0')
            ->and(is_file($this->server.'/notas-1.1.0.txt'))->toBeFalse()
            ->and($log)->toContain($restore)
            ->and(array_search('migrate --force', $log, true))->toBeLessThan(array_search($restore, $log, true))
            ->and(end($log))->toBe('up')
            ->and(file_get_contents($this->server.'/storage/app/private/deploy/history.log'))->toContain('FALLÓ y se revirtió');
    });

    it('si falla antes de migrar no restaura la base de datos', function () {
        publishVersion($this->dev, '1.1.0');
        $broken = $this->tmp.'/bin/composer-roto';
        file_put_contents($broken, "#!/usr/bin/env bash\necho \"composer \$*\" >> \"\$DEPLOY_LOG\"\n[ -f \"\$DEPLOY_LOG.fallar\" ] && exit 1\nexit 0\n");
        file_put_contents($this->log.'.fallar', '');
        $old = gitIn($this->server, 'rev-parse', 'HEAD');

        [$code, $output] = ($this->deploy)(['--yes'], ['COMPOSER_BIN' => $broken]);
        $log = ($this->logged)();

        expect($code)->toBe(1, $output)
            ->and(gitIn($this->server, 'rev-parse', 'HEAD'))->toBe($old)
            ->and(implode("\n", $log))->not->toContain('db:restore')
            ->and(end($log))->toBe('up');
    });

    it('no hace nada si el servidor ya está al día', function () {
        [$code, $output] = ($this->deploy)(['--yes']);

        expect($code)->toBe(0, $output)->and($output)->toContain('Ya está al día')->and(($this->logged)())->not->toContain('down --retry=60');
    });

    it('no permite bajar de versión', function () {
        publishVersion($this->dev, '0.9.0');
        $before = gitIn($this->server, 'rev-parse', 'HEAD');

        [$code, $output] = ($this->deploy)(['--yes']);

        expect($code)->toBe(1)
            ->and($output)->toContain('No se permite instalar una versión anterior')
            ->and(gitIn($this->server, 'rev-parse', 'HEAD'))->toBe($before)
            ->and(($this->logged)())->not->toContain('down --retry=60');
    });

    it('se niega con cambios a mano, en otra rama, con una rama sin assets o sin comando de respaldo', function () {
        publishVersion($this->dev, '1.1.0');

        file_put_contents($this->server.'/public/build/manifest.json', '{"editado":true}');
        [$code, $output] = ($this->deploy)(['--yes']);
        expect($code)->toBe(1)->and($output)->toContain('cambios sin confirmar');
        gitIn($this->server, 'checkout', '-q', '--', 'public/build/manifest.json');

        gitIn($this->server, 'checkout', '-q', '-b', 'otra');
        [$code, $output] = ($this->deploy)(['--yes']);
        expect($code)->toBe(1)->and($output)->toContain('Estás en la rama «otra»');
        gitIn($this->server, 'checkout', '-q', 'production');

        [$code, $output] = ($this->deploy)(['--yes'], ['DEPLOY_STUB_NO_BACKUP' => '1']);
        expect($code)->toBe(1)->and($output)->toContain('db:backup');

        publishVersion($this->dev, '1.2.0', ['public/build/manifest.json' => null]);
        [$code, $output] = ($this->deploy)(['--yes']);
        expect($code)->toBe(1)->and($output)->toContain('no trae public/build');

        expect(($this->logged)())->not->toContain('down --retry=60');
    });

    it('--no-backup permite actualizar cuando el servidor aún no tiene db:backup', function () {
        publishVersion($this->dev, '1.1.0');

        [$code, $output] = ($this->deploy)(['--yes', '--no-backup'], ['DEPLOY_STUB_NO_BACKUP' => '1']);

        expect($code)->toBe(0, $output)->and(trim(file_get_contents($this->server.'/VERSION')))->toBe('1.1.0');
    });

    it('--rollback vuelve al commit anterior a la última actualización', function () {
        $old = gitIn($this->server, 'rev-parse', 'HEAD');
        publishVersion($this->dev, '1.1.0');
        ($this->deploy)(['--yes']);

        [$code, $output] = ($this->deploy)(['--rollback', '--yes']);

        expect($code)->toBe(0, $output)
            ->and(gitIn($this->server, 'rev-parse', 'HEAD'))->toBe($old)
            ->and(trim(file_get_contents($this->server.'/VERSION')))->toBe('1.0.0')
            ->and(file_get_contents($this->server.'/storage/app/private/deploy/history.log'))->toContain('reversión manual');
    });
});
