#!/usr/bin/env bash
#
# Actualiza la aplicación EN EL SERVIDOR con "git pull" sobre la rama de producción (hosting con SSH).
# Es la contraparte, para servidores con SSH, de /admin/actualizaciones (hosting sin SSH). La rama "production" la
# prepara scripts/release.ps1: cada versión se fusiona ahí con public/build ya compilado.
#
# Qué hace, en orden (se detiene en el primer problema y, si ya había empezado a cambiar algo, lo revierte):
#   1. Comprobaciones: herramientas, rama, árbol limpio, .env, versión nueva más reciente que la instalada, assets.
#   2. SIEMPRE muestra los commits que llegan y el plan paso a paso; después pregunta si continuar o salir
#      (--yes omite solo la pregunta, no el plan).
#   3. Modo de mantenimiento (php artisan down).
#   4. Respaldo de la base de datos (php artisan db:backup) antes de tocar nada.
#   5. git merge --ff-only de la rama remota (nunca mezcla ni reescribe: si divergió, no hace nada).
#   6. composer install --no-dev, php artisan migrate --force y cachés de configuración, eventos y vistas.
#   7. Comprueba que VERSION coincide, que no quedan migraciones pendientes y que la aplicación arranca.
#   8. Quita el mantenimiento y anota el resultado en storage/app/private/deploy/history.log.
#
# Si algo falla después del paso 3: restaura la base de datos (si ya migró), vuelve al commit anterior, reinstala las
# dependencias de ese commit y quita el mantenimiento.
#
# USO:   bash scripts/deploy-servidor.sh               pregunta antes de actualizar
#        bash scripts/deploy-servidor.sh --dry-run     solo muestra qué haría
#        bash scripts/deploy-servidor.sh --yes         sin preguntar (para automatizar)
#        bash scripts/deploy-servidor.sh --rollback    vuelve al commit anterior a la última actualización
#   Opciones: --branch production  --remote origin  --no-backup (no recomendado)
#   Variables: PHP_BIN (por omisión php), COMPOSER_BIN (por omisión composer)

set -u

PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
REMOTE="origin"
BRANCH="production"
DRY_RUN=0
ASSUME_YES=0
NO_BACKUP=0
ROLLBACK_ONLY=0

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run) DRY_RUN=1 ;;
        --yes) ASSUME_YES=1 ;;
        --no-backup) NO_BACKUP=1 ;;
        --rollback) ROLLBACK_ONLY=1 ;;
        --remote) REMOTE="${2:-}"; shift ;;
        --branch) BRANCH="${2:-}"; shift ;;
        -h | --help) sed -n '2,33p' "$0"; exit 0 ;;
        *) echo "Opción desconocida: $1" >&2; exit 2 ;;
    esac
    shift
done

cd "$(dirname "$0")/.." || exit 2

STATE_DIR="storage/app/private/deploy"
PREVIOUS=""
OLD_VERSION=""
NEW_VERSION=""
BACKUP_FILE=""
STARTED=0
CHANGED=0
MIGRATED=0
MAINTENANCE=0

info() { printf '\n== %s\n' "$1"; }
ok() { printf '  [OK] %s\n' "$1"; }
aviso() { printf '  [!]  %s\n' "$1"; }
fallo() { printf '  [X]  %s\n' "$1" >&2; }

registrar() {
    mkdir -p "$STATE_DIR"
    printf '%s  %s -> %s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "${OLD_VERSION:-?}" "${NEW_VERSION:-?}" "$1" >> "$STATE_DIR/history.log"
}

# Deja el servidor como estaba: base de datos (si ya se migró), código y mantenimiento.
revertir() {
    aviso "Revirtiendo la actualización..."
    local problemas=0

    if [ "$MIGRATED" = 1 ] && [ -n "$BACKUP_FILE" ]; then
        "$PHP_BIN" artisan db:restore "$BACKUP_FILE" --yes || { fallo "No se pudo restaurar la base de datos: restáurala a mano con $BACKUP_FILE"; problemas=1; }
    fi

    if [ "$CHANGED" = 1 ] && [ -n "$PREVIOUS" ]; then
        git reset --hard "$PREVIOUS" || problemas=1
        "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction || problemas=1
    fi

    "$PHP_BIN" artisan optimize:clear > /dev/null 2>&1

    if [ "$MAINTENANCE" = 1 ]; then
        "$PHP_BIN" artisan up && MAINTENANCE=0 || { fallo "No se pudo quitar el mantenimiento: php artisan up"; problemas=1; }
    fi

    if [ "$problemas" = 0 ]; then ok "La aplicación quedó como estaba."; else fallo "La reversión no terminó sola: revisa los mensajes anteriores."; fi
}

falla() {
    fallo "$1"

    if [ "$STARTED" = 1 ]; then
        revertir
        registrar "FALLÓ y se revirtió: $1"
    fi

    exit 1
}

# paso "descripción" comando args...: ejecuta y, si falla, aborta (y revierte si ya se había empezado).
paso() {
    local descripcion="$1"
    shift
    printf '  > %s\n' "$descripcion"
    "$@"
    local codigo=$?
    [ "$codigo" -eq 0 ] || falla "Falló: $descripcion (código $codigo)"
}

mayor_que() { "$PHP_BIN" -r 'exit(version_compare($argv[1], $argv[2], ">") ? 0 : 1);' "$1" "$2"; }

# Pregunta si continuar o salir. Solo sigue con «c» (o «continuar»); cualquier otra respuesta, incluido Enter, sale sin
# cambiar nada. Con --yes (automatización) no pregunta, pero el plan ya se mostró.
confirmar() {
    if [ "$ASSUME_YES" = 1 ]; then
        echo
        aviso "--yes: se continúa sin preguntar."
        return 0
    fi

    echo
    # printf y no "read -p": así la pregunta también queda escrita cuando la entrada no es un terminal.
    printf '  %s [c = continuar / s = salir] (por omisión: salir): ' "$1"
    respuesta=""
    read -r respuesta || true
    case "$respuesta" in c | C | continuar | CONTINUAR) return 0 ;; esac

    echo
    echo "  Saliste: no se cambió nada."
    exit 0
}

# ---------------------------------------------------------------------------------------------------------------
info "1. Comprobaciones"

for herramienta in git "$PHP_BIN" "$COMPOSER_BIN"; do
    command -v "$herramienta" > /dev/null 2>&1 || falla "No se encontró «$herramienta»."
done

git rev-parse --is-inside-work-tree > /dev/null 2>&1 || falla "Esto no es un repositorio de git."
[ -f .env ] || falla "Falta el archivo .env: esta carpeta no es una instalación configurada."

mkdir -p "$STATE_DIR"

if command -v flock > /dev/null 2>&1; then
    exec 9> "$STATE_DIR/.lock"
    flock -n 9 || falla "Ya hay otra actualización en curso."
fi

ACTUAL_BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$ACTUAL_BRANCH" = "$BRANCH" ] || falla "Estás en la rama «$ACTUAL_BRANCH» y se actualiza desde «$BRANCH»."
ok "Rama $BRANCH"

PREVIOUS="$(git rev-parse HEAD)"
OLD_VERSION="$(tr -d '[:space:]' < VERSION 2> /dev/null || echo '?')"

if [ "$ROLLBACK_ONLY" = 1 ]; then
    info "Reversión manual"
    [ -f "$STATE_DIR/previous-commit" ] || falla "No hay una actualización anterior registrada ($STATE_DIR/previous-commit)."
    DESTINO="$(cat "$STATE_DIR/previous-commit")"
    git cat-file -e "$DESTINO^{commit}" 2> /dev/null || falla "El commit $DESTINO ya no existe en este repositorio."
    echo
    info "Plan: lo que se va a hacer"
    echo "     1. Poner la aplicación en mantenimiento (php artisan down)."
    echo "     2. Volver del commit $(git rev-parse --short HEAD) al $(git rev-parse --short "$DESTINO") (git reset --hard)."
    echo "     3. Reinstalar las dependencias de ese commit (composer install --no-dev)."
    echo "     4. Limpiar cachés y quitar el mantenimiento (php artisan up)."
    echo "     La base de datos NO se restaura sola: si la actualización migró, usa php artisan db:restore."

    confirmar "¿Volver al commit anterior?"

    STARTED=1
    CHANGED=1
    PREVIOUS="$DESTINO"
    "$PHP_BIN" artisan down --retry=60 && MAINTENANCE=1
    paso "git reset --hard $(git rev-parse --short "$DESTINO")" git reset --hard "$DESTINO"
    paso "composer install --no-dev" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
    "$PHP_BIN" artisan optimize:clear > /dev/null 2>&1
    paso "php artisan up" "$PHP_BIN" artisan up
    MAINTENANCE=0
    registrar "reversión manual al commit $(git rev-parse --short "$DESTINO")"
    ok "Código devuelto al commit anterior."
    exit 0
fi

[ -z "$(git status --porcelain --untracked-files=no)" ] || falla "Hay cambios sin confirmar en archivos del repositorio; una actualización no debe mezclarse con ediciones a mano."
ok "Árbol de trabajo limpio"

if [ "$NO_BACKUP" = 0 ]; then
    "$PHP_BIN" artisan list --raw 2> /dev/null | grep -q '^db:backup' || falla "Este servidor aún no tiene el comando db:backup: no se puede respaldar la base de datos. Usa --no-backup bajo tu responsabilidad."
fi

paso "git fetch $REMOTE $BRANCH" git fetch --quiet "$REMOTE" "$BRANCH"

OBJETIVO="$(git rev-parse "$REMOTE/$BRANCH" 2> /dev/null)" || falla "No existe $REMOTE/$BRANCH."

if [ "$OBJETIVO" = "$PREVIOUS" ]; then
    ok "Ya está al día (versión $OLD_VERSION, commit $(git rev-parse --short HEAD))."
    exit 0
fi

git merge-base --is-ancestor "$PREVIOUS" "$OBJETIVO" || falla "La rama local y $REMOTE/$BRANCH divergieron: no se puede avanzar sin mezclar. Revísalo a mano."

NEW_VERSION="$(git show "$REMOTE/$BRANCH:VERSION" 2> /dev/null | tr -d '[:space:]')"
[ -n "$NEW_VERSION" ] || falla "$REMOTE/$BRANCH no tiene archivo VERSION."

if [ "$NEW_VERSION" != "$OLD_VERSION" ]; then
    mayor_que "$NEW_VERSION" "$OLD_VERSION" || falla "No se permite instalar una versión anterior: está la $OLD_VERSION y $REMOTE/$BRANCH trae la $NEW_VERSION."
else
    aviso "La versión es la misma ($OLD_VERSION): llegan cambios de una regeneración."
fi

git cat-file -e "$OBJETIVO:public/build/manifest.json" 2> /dev/null || falla "$REMOTE/$BRANCH no trae public/build (assets compilados): la rama no la preparó scripts/release.ps1."
ok "Versión $OLD_VERSION -> $NEW_VERSION"

echo
echo "  Commits que llegan:"
git log --oneline --no-decorate "$PREVIOUS..$OBJETIVO" | head -15 | sed 's/^/     /'

echo
info "Plan: lo que se va a hacer (de la versión $OLD_VERSION a la $NEW_VERSION)"
echo "     1. Poner la aplicación en mantenimiento (php artisan down)."
if [ "$NO_BACKUP" = 0 ]; then
    echo "     2. Respaldar la base de datos (php artisan db:backup, etiqueta antes-de-v$NEW_VERSION)."
else
    echo "     2. (omitido) Respaldo de la base de datos: usaste --no-backup."
fi
echo "     3. Traer los cambios con git merge --ff-only $REMOTE/$BRANCH ($(git rev-parse --short "$PREVIOUS") -> $(git rev-parse --short "$OBJETIVO"))."
echo "     4. Instalar dependencias (composer install --no-dev --optimize-autoloader)."
echo "     5. Aplicar las migraciones (php artisan migrate --force)."
echo "     6. Regenerar cachés: configuración, eventos, vistas y, si se puede, rutas."
echo "     7. Comprobar que VERSION es $NEW_VERSION, que no quedan migraciones pendientes y que la aplicación arranca."
echo "     8. Quitar el mantenimiento (php artisan up)."
echo "     Si algo falla: restaura la base de datos (si ya migró), vuelve al commit $(git rev-parse --short "$PREVIOUS") y quita el mantenimiento."

if [ "$DRY_RUN" = 1 ]; then
    echo
    ok "Simulación (--dry-run): no se cambió nada."
    exit 0
fi

confirmar "¿Continuar con la actualización a la versión $NEW_VERSION?"

# ---------------------------------------------------------------------------------------------------------------
info "2. Actualización"
echo "$PREVIOUS" > "$STATE_DIR/previous-commit"
STARTED=1

paso "php artisan down" "$PHP_BIN" artisan down --retry=60
MAINTENANCE=1

if [ "$NO_BACKUP" = 0 ]; then
    printf '  > php artisan db:backup\n'
    SALIDA="$("$PHP_BIN" artisan db:backup --no-ansi --label="antes-de-v$NEW_VERSION" 2>&1)"
    CODIGO=$?
    printf '%s\n' "$SALIDA" | sed 's/^/    /'
    [ "$CODIGO" -eq 0 ] || falla "Falló el respaldo de la base de datos (código $CODIGO)."
    BACKUP_FILE="$(printf '%s\n' "$SALIDA" | sed -n 's/^[[:space:]]*Archivo[[:space:].]*//p' | head -1 | tr -d '\r')"
    [ -n "$BACKUP_FILE" ] || falla "No se pudo leer la ruta del respaldo: no se sigue sin él."
fi

CHANGED=1
paso "git merge --ff-only $REMOTE/$BRANCH" git merge --ff-only --quiet "$OBJETIVO"
paso "composer install --no-dev" "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

MIGRATED=1
paso "php artisan migrate --force" "$PHP_BIN" artisan migrate --force

"$PHP_BIN" artisan optimize:clear > /dev/null 2>&1
paso "php artisan config:cache" "$PHP_BIN" artisan config:cache
paso "php artisan event:cache" "$PHP_BIN" artisan event:cache
paso "php artisan view:cache" "$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan route:cache > /dev/null 2>&1 || aviso "route:cache no se pudo (rutas con closures): se omite; no afecta el funcionamiento."

# ---------------------------------------------------------------------------------------------------------------
info "3. Comprobación"
INSTALADA="$(tr -d '[:space:]' < VERSION)"
[ "$INSTALADA" = "$NEW_VERSION" ] || falla "Tras actualizar, VERSION dice $INSTALADA y debería decir $NEW_VERSION."
ok "VERSION $INSTALADA"

PENDIENTES="$("$PHP_BIN" artisan migrate:status --no-ansi 2> /dev/null | grep -ci 'pending')"
[ "${PENDIENTES:-0}" = 0 ] || falla "Quedan $PENDIENTES migraciones pendientes."
ok "Sin migraciones pendientes"

paso "La aplicación arranca (php artisan route:list)" sh -c "\"$PHP_BIN\" artisan route:list > /dev/null 2>&1"

paso "php artisan up" "$PHP_BIN" artisan up
MAINTENANCE=0

registrar "OK commit $(git rev-parse --short HEAD)"
echo
ok "Versión $NEW_VERSION instalada. Comprueba /health con la cabecera X-Health-Token y ejecuta php artisan security:check --strict."
[ -n "$BACKUP_FILE" ] && echo "  Respaldo previo: $BACKUP_FILE"
echo "  Para volver atrás: bash scripts/deploy-servidor.sh --rollback"
