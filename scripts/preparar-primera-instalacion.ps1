<#
.SYNOPSIS
    Prepara, en tu equipo, la PRIMERA instalación de la aplicación en un hosting sin SSH (p. ej. InfinityFree).

.DESCRIPTION
    /admin/actualizaciones solo sirve para ACTUALIZAR una aplicación que ya está instalada. La primera vez se usa el
    mismo paquete firmado (releases\vX.Y.Z) pero descomprimido y subido por FTP. Este script deja todo listo en
    releases\instalar-vX.Y.Z\ (php artisan release:first-install):

      sitio\                 el paquete verificado (firma, hashes y rutas) y descomprimido: es lo que se sube por FTP,
                             con el esqueleto de storage y el inventario que usará la primera actualización
      env-produccion.txt     plantilla del .env de producción (APP_KEY y HEALTH_TOKEN nuevos; rellena los «CAMBIAR»)
      base-de-datos\         base de datos inicial (roles, permisos y un administrador) en .sql.gz y .sql, para
                             importarla con phpMyAdmin
      LEEME.txt              los pasos para subirlo

    La base de datos se construye en una base TEMPORAL de tu MySQL local (proy03_instalar_...), se vuelca y se borra:
    tu base de desarrollo no se toca. El administrador recibe una contraseña aleatoria que se muestra UNA sola vez.

.PARAMETER Version
    Versión a instalar. Por omisión, la del archivo VERSION (debe existir releases\vX.Y.Z; si no, genera el paquete
    con .\scripts\release.ps1).

.PARAMETER AdminCorreo
    Correo del administrador inicial. Si se omite, lo pregunta.

.PARAMETER AdminNombre
    Nombre del administrador inicial. Por omisión, Administrador.

.PARAMETER SinBaseDeDatos
    No prepara la base de datos inicial (solo el sitio y la plantilla del .env).

.PARAMETER ConClasificaciones
    Incluye en la base de datos las clasificaciones de ejemplo.

.EXAMPLE
    .\scripts\preparar-primera-instalacion.ps1
    .\scripts\preparar-primera-instalacion.ps1 -Version 1.0.0 -AdminCorreo yo@midominio.com
    .\scripts\preparar-primera-instalacion.ps1 -SinBaseDeDatos
#>
[CmdletBinding()]
param(
    [string]$Version,
    [string]$AdminCorreo,
    [string]$AdminNombre = 'Administrador',
    [switch]$SinBaseDeDatos,
    [switch]$ConClasificaciones
)

$ErrorActionPreference = 'Continue'
$Raiz = Split-Path -Parent $PSScriptRoot
Set-Location $Raiz

function Titulo([string]$Texto) { Write-Host ''; Write-Host "== $Texto" -ForegroundColor Cyan }
function Ok([string]$Texto) { Write-Host "  [OK] $Texto" -ForegroundColor Green }
function Aviso([string]$Texto) { Write-Host "  [!]  $Texto" -ForegroundColor Yellow }
function Fallo([string]$Texto) { Write-Host "  [X]  $Texto" -ForegroundColor Red }

function Confirmar([string]$Pregunta, [bool]$PorDefecto = $true) {
    $sufijo = if ($PorDefecto) { 'S/n' } else { 's/N' }
    while ($true) {
        $respuesta = (Read-Host "  $Pregunta [$sufijo]").Trim().ToLower()
        if ($respuesta -eq '') { return $PorDefecto }
        if ($respuesta -in @('s', 'si', 'sí', 'y', 'yes')) { return $true }
        if ($respuesta -in @('n', 'no')) { return $false }
    }
}

try {
    Write-Host ''
    Write-Host 'Preparación de la primera instalación (hosting sin SSH)' -ForegroundColor White

    Titulo '1. Requisitos'
    if (-not (Get-Command php -ErrorAction SilentlyContinue)) { throw 'No se encontró «php» en el PATH.' }
    if (-not (Test-Path (Join-Path $Raiz 'artisan'))) { throw 'No se encontró artisan: ejecuta el script desde el proyecto.' }
    Ok 'php y artisan disponibles'

    if (-not $Version) { $Version = (Get-Content (Join-Path $Raiz 'VERSION') -Raw).Trim() }
    $paquete = Join-Path $Raiz "releases\v$Version"

    if (-not (Test-Path (Join-Path $paquete 'manifest.json'))) {
        throw "No existe el paquete $paquete. Genéralo con .\scripts\release.ps1 (opción 4 si la versión $Version ya está publicada)."
    }
    Ok "Paquete de la versión $Version"

    $salida = Join-Path $Raiz "releases\instalar-v$Version"
    if (Test-Path $salida) {
        $anterior = "$salida.anterior-" + (Get-Date -Format 'yyyyMMdd-HHmmss')
        Aviso "Ya existe $salida."
        if (-not (Confirmar "¿Conservarla como $(Split-Path $anterior -Leaf) y preparar una nueva?" $true)) { throw 'Cancelado: no se cambió nada.' }
        Rename-Item -Path $salida -NewName (Split-Path $anterior -Leaf)
    }

    $argumentos = @('artisan', 'release:first-install', $paquete, "--output=$salida", "--admin-name=$AdminNombre")

    if ($SinBaseDeDatos) {
        $argumentos += '--no-database'
    } else {
        Titulo '2. Administrador inicial y base de datos'
        Write-Host '  Se creará una base de datos TEMPORAL en tu MySQL local (proy03_instalar_...) con las migraciones, los roles'
        Write-Host '  y el administrador; se volcará a un archivo y se borrará. Tu base de desarrollo no se toca.'

        while ($true) {
            if (-not $AdminCorreo) { $AdminCorreo = (Read-Host '  Correo del administrador inicial').Trim() }
            if ($AdminCorreo -match '^[^@\s]+@[^@\s]+\.[^@\s]+$') { break }
            Fallo 'Escribe un correo con formato válido.'
            $AdminCorreo = $null
        }

        $argumentos += "--admin-email=$AdminCorreo"
        if ($ConClasificaciones) { $argumentos += '--with-classifications' }
    }

    Titulo '3. Preparando (verifica el paquete, lo descomprime y arma la base de datos)'
    $global:LASTEXITCODE = 0
    & php @argumentos | Out-Host
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la primera instalación (ver el mensaje anterior).' }

    Titulo 'Listo'
    Ok "Todo está en $salida"
    Write-Host '  Sigue los pasos de LEEME.txt:'
    Write-Host '     1. Sube por FTP TODO el contenido de la carpeta «sitio» (permiso de escritura a storage y bootstrap\cache).'
    Write-Host '     2. Rellena los «CAMBIAR» de env-produccion.txt y súbelo como «.env»; luego BÓRRALO de tu equipo.'
    if (-not $SinBaseDeDatos) {
        Write-Host '     3. Importa base-de-datos\base-de-datos-inicial.sql.gz con phpMyAdmin (o el .sql).'
        Write-Host '     Guarda la contraseña del administrador (se mostró arriba una sola vez) y cámbiala al entrar.'
    }
    Write-Host '     No subas nunca la clave privada de firma ni tu .env de desarrollo.'

    if (Confirmar '¿Abrir la carpeta ahora?' $true) { Start-Process explorer.exe -ArgumentList "`"$salida`"" }
    exit 0
} catch {
    Write-Host ''
    Fallo $_.Exception.Message
    exit 1
}
