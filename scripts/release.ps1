<#
.SYNOPSIS
    Prepara una versión nueva de la aplicación (entorno de DESARROLLO): verifica, versiona, empaqueta y firma.

.DESCRIPTION
    Script interactivo. Se detiene en el primer problema y dice qué corregir. Pasos:
      1. Requisitos: herramientas instaladas, rama base, cambios sin confirmar, clave de firma.
      2. Verificaciones: formato (Pint), pruebas, auditoría de Composer y npm, compilación de assets.
      3. Versión: elige patch / minor / major y calcula la siguiente a partir del archivo VERSION.
      4. CHANGELOG.md: arma la sección de la versión con los commits desde la etiqueta anterior (puedes editarla).
      5. Commit "chore(release)" y etiqueta vX.Y.Z (si algo falla después, ofrece deshacerlos).
      6. Paquete: código de la etiqueta + vendor sin dependencias de desarrollo + public/build, en un ZIP partido en
         trozos de 9 MiB, con manifest.json firmado (php artisan release:package) y verificado dos veces.
      7. Rama de producción (opcional): fusiona la etiqueta en la rama "production" con los assets compilados, para
         actualizar el servidor con git pull (hosting con SSH). No sube nada sin que lo confirmes.

    Resultado: releases\vX.Y.Z\ (partes del ZIP, manifest.json, manifest.json.sig) y una línea en releases\historial.log.

    La clave PRIVADA de firma nunca está en el proyecto. Si falta, el script la crea (php artisan release:keygen) en
    <carpeta del proyecto>_keys\private\AAAAMMDD-HH-mm-release-private.key, protegida con la contraseña que le pidas;
    la pública queda en ...\public\ y en config\release-public.key (esa sí se sube al repositorio). Después registra la
    ruta en el .env (RELEASE_SIGNING_KEY_FILE) con copia previa del .env, y te recuerda guardar copias de la carpeta
    de claves (USB, nube cifrada) y la contraseña (gestor de contraseñas). No se envía nada por correo.

.PARAMETER Tipo
    patch (corrección), minor (función nueva compatible), major (cambio que rompe compatibilidad) o regenerar
    (sin cambiar la versión: genera o vuelve a generar el paquete firmado de la versión actual a partir de su etiqueta,
    que se crea en el commit actual si todavía no existe; la carpeta anterior se conserva como
    releases\vX.Y.Z.anterior-FECHA). Si se omite, lo pregunta.

.PARAMETER Modo
    paquete (ZIP en partes, para hosting sin SSH), git (rama de producción) o ambos. Si se omite se usan ambos
    (al regenerar, solo el paquete).

.PARAMETER RamaBase
    Rama de la que se publica. Por omisión, main.

.PARAMETER RamaProduccion
    Rama que recibe cada versión para "git pull" en el servidor. Por omisión, production.

.PARAMETER Remoto
    Remoto de git al que se sube. Por omisión, origin.

.PARAMETER SoloVerificar
    Ejecuta solo las verificaciones del paso 2 y termina, sin cambiar nada.

.PARAMETER RotarClaves
    Crea un par de claves nuevo SIN descartar el actual: la pública nueva se guarda en config\release-public-keys\ y la
    clave vigente sigue firmando hasta que publiques una versión de transición y cambies con release:use-key.

.EXAMPLE
    .\scripts\release.ps1
    .\scripts\release.ps1 -Tipo minor -Modo paquete
    .\scripts\release.ps1 -SoloVerificar
#>
[CmdletBinding()]
param(
    [ValidateSet('patch', 'minor', 'major', 'regenerar')][string]$Tipo,
    [ValidateSet('paquete', 'git', 'ambos')][string]$Modo,
    [string]$RamaBase = 'main',
    [string]$RamaProduccion = 'production',
    [string]$Remoto = 'origin',
    [switch]$SoloVerificar,
    [switch]$RotarClaves
)

# Los errores de programas externos (git, php, npm) se revisan con $LASTEXITCODE; PowerShell no debe tratarlos como excepciones.
$ErrorActionPreference = 'Continue'
$Raiz = Split-Path -Parent $PSScriptRoot
Set-Location $Raiz

$Utf8SinBom = New-Object System.Text.UTF8Encoding($false)
$Resumen = New-Object System.Collections.Generic.List[string]

function Titulo([string]$Texto) { Write-Host ''; Write-Host "== $Texto" -ForegroundColor Cyan }
function Ok([string]$Texto) { Write-Host "  [OK] $Texto" -ForegroundColor Green }
function Aviso([string]$Texto) { Write-Host "  [!]  $Texto" -ForegroundColor Yellow }
function Fallo([string]$Texto) { Write-Host "  [X]  $Texto" -ForegroundColor Red }
function Detener([string]$Texto) { throw $Texto }

function Confirmar([string]$Pregunta, [bool]$PorDefecto = $true) {
    $sufijo = if ($PorDefecto) { 'S/n' } else { 's/N' }
    while ($true) {
        $respuesta = (Read-Host "  $Pregunta [$sufijo]").Trim().ToLower()
        if ($respuesta -eq '') { return $PorDefecto }
        if ($respuesta -in @('s', 'si', 'sí', 'y', 'yes')) { return $true }
        if ($respuesta -in @('n', 'no')) { return $false }
    }
}

function Ejecutar([string]$Descripcion, [scriptblock]$Bloque) {
    Write-Host "  > $Descripcion" -ForegroundColor DarkGray
    $global:LASTEXITCODE = 0
    # Out-Host: la salida del programa se ve en pantalla y no se mezcla con lo que devuelve la función que llama.
    & $Bloque | Out-Host
    if ($LASTEXITCODE -ne 0) { throw "Falló: $Descripcion (código de salida $LASTEXITCODE)." }
}

function Salida([scriptblock]$Bloque) {
    $global:LASTEXITCODE = 0
    $texto = & $Bloque
    if ($LASTEXITCODE -ne 0) { return $null }
    return ($texto | Out-String).Trim()
}

# ---------------------------------------------------------------------------------------------------------------
# 1. Requisitos
# ---------------------------------------------------------------------------------------------------------------
function Probar-Requisitos {
    Titulo '1. Requisitos'

    foreach ($herramienta in 'git', 'php', 'composer', 'npm') {
        if (-not (Get-Command $herramienta -ErrorAction SilentlyContinue)) { Detener "No se encontró «$herramienta» en el PATH." }
    }
    Ok 'git, php, composer y npm disponibles'

    $rama = Salida { git rev-parse --abbrev-ref HEAD }
    if ($rama -ne $RamaBase) {
        Aviso "Estás en la rama «$rama», no en «$RamaBase»."
        if (-not (Confirmar '¿Publicar desde esta rama de todos modos?' $false)) { Detener 'Cancelado: cámbiate a la rama base.' }
    } else {
        Ok "Rama $rama"
    }

    $sinConfirmar = git status --porcelain
    if ($sinConfirmar) {
        Fallo 'Hay cambios sin confirmar; una versión sale de un commit, no de archivos sueltos:'
        $sinConfirmar | Select-Object -First 15 | ForEach-Object { Write-Host "       $_" }
        Detener 'Haz commit (o descarta) esos cambios y vuelve a ejecutar el script.'
    }
    Ok 'Árbol de trabajo limpio'
}

function Obtener-RutaClaveFirma {
    $ruta = $env:RELEASE_SIGNING_KEY_FILE
    if (-not $ruta -and (Test-Path '.env')) {
        $linea = Select-String -Path '.env' -Pattern '^\s*RELEASE_SIGNING_KEY_FILE\s*=\s*(.*)$' | Select-Object -First 1
        if ($linea) { $ruta = $linea.Matches[0].Groups[1].Value.Trim().Trim('"').Trim("'") }
    }
    return $ruta
}

function Leer-Contrasena([string]$Pregunta) {
    $segura = Read-Host "  $Pregunta" -AsSecureString
    $puntero = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($segura)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($puntero) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($puntero) }
}

function Clave-EstaProtegida([string]$Ruta) {
    $primera = Get-Content -LiteralPath $Ruta -TotalCount 1
    return [bool]($primera -and $primera.TrimStart().StartsWith('{'))
}

# Si la clave tiene contraseña, la pide (sin mostrarla), comprueba que descifra y la guarda en $script:ContrasenaFirma.
# Solo se pone en el entorno del proceso de php durante la firma (ver Crear-Paquete) y nunca se escribe en un archivo.
function Pedir-ContrasenaFirma([string]$Ruta) {
    $script:ContrasenaFirma = $null

    if (-not (Clave-EstaProtegida $Ruta)) {
        Aviso 'La clave privada NO tiene contraseña: se recomienda crear una nueva que sí la tenga.'
        return
    }

    $script:ContrasenaFirma = $env:RELEASE_KEY_PASSPHRASE
    for ($intento = 1; $intento -le 3; $intento++) {
        if (-not $script:ContrasenaFirma) { $script:ContrasenaFirma = Leer-Contrasena 'Contraseña de la clave de firma' }

        $env:RELEASE_KEY_PASSPHRASE = $script:ContrasenaFirma
        $global:LASTEXITCODE = 0
        php artisan release:keycheck --deep | Out-Null
        $codigo = $LASTEXITCODE
        Remove-Item Env:RELEASE_KEY_PASSPHRASE -ErrorAction SilentlyContinue

        if ($codigo -eq 0) { Ok 'Contraseña correcta; la clave descifra y firma'; return }
        Fallo "Contraseña incorrecta (intento $intento de 3)"
        $script:ContrasenaFirma = $null
    }
    Detener 'No se pudo descifrar la clave de firma.'
}

function Mostrar-ResguardoDeClaves([string]$Ruta) {
    $carpeta = Split-Path (Split-Path $Ruta -Parent) -Parent
    Write-Host ''
    Aviso 'Resguardo de las claves (hazlo ahora; sin la privada no se pueden firmar versiones):'
    Write-Host "     Carpeta de claves: $carpeta"
    Write-Host '     1. Copia la carpeta completa a un USB guardado aparte y/o a una nube cifrada.'
    Write-Host '     2. Guarda la contraseña en un gestor de contraseñas, nunca junto al archivo.'
    Write-Host '     3. No envíes la clave privada por correo ni por mensajería.'
    Write-Host '     4. La huella que se mostró al crear el par identifica la clave: aparece como «signing_key» en cada paquete.'
    if (Confirmar '¿Abrir la carpeta de claves ahora?' $true) { Start-Process explorer.exe -ArgumentList "`"$carpeta`"" }
    Verificar-Resguardo $Ruta
}

# Comprueba que una copia de resguardo (USB, otra carpeta) es idéntica a la clave privada original, comparando su SHA-256.
function Verificar-Resguardo([string]$Ruta) {
    if (-not (Confirmar '¿Verificar ahora una copia de resguardo (USB u otra carpeta)?' $false)) { return }

    $copia = (Read-Host '  Carpeta de la copia (la que contiene private\ y public\)').Trim().Trim('"')
    $archivo = Join-Path (Join-Path $copia 'private') (Split-Path $Ruta -Leaf)

    if (-not (Test-Path -LiteralPath $archivo)) { Fallo "No se encontró ${archivo}: la copia no tiene esta clave."; return }

    $original = (Get-FileHash -LiteralPath $Ruta -Algorithm SHA256).Hash
    $respaldo = (Get-FileHash -LiteralPath $archivo -Algorithm SHA256).Hash

    if ($original -eq $respaldo) { Ok 'La copia es idéntica a la clave privada original (mismo SHA-256).' }
    else { Fallo 'La copia es DISTINTA de la original: vuelve a copiar el archivo.' }
}

function Registrar-Clave([string]$Ruta) {
    Write-Host ''
    Write-Host '  Se registrará la clave en el .env (solo RELEASE_SIGNING_KEY_FILE; antes se guarda una copia del .env fuera del proyecto):'
    Ejecutar 'php artisan release:use-key --dry-run' { php artisan release:use-key $Ruta --dry-run }
    if (-not (Confirmar '¿Aplicar el cambio al .env?' $true)) { Detener 'Cancelado: el .env no se tocó.' }
    Ejecutar 'php artisan release:use-key' { php artisan release:use-key $Ruta --yes }
    Ejecutar 'php artisan release:keycheck' { php artisan release:keycheck }
}

function Crear-ParDeClaves([switch]$Rotar) {
    $argumentos = @('artisan', 'release:keygen')

    if (Test-Path (Join-Path $Raiz 'config\release-public.key')) {
        if (-not $Rotar) {
            Aviso 'El proyecto ya tiene una clave pública (config\release-public.key).'
            Write-Host '  1) Rotar: crear una clave nueva y conservar la actual como de confianza (hace falta la privada actual para firmar la versión de transición)'
            Write-Host '  2) Reemplazar: la nueva pasa a ser la única; invalida las versiones ya firmadas con la anterior (si perdiste la privada)'
            Write-Host '  3) Cancelar'
            switch ((Read-Host '  Elige 1, 2 o 3').Trim()) {
                '1' { $Rotar = [switch]$true }
                '2' { }
                default { Detener 'Cancelado.' }
            }
        }
        if (-not $Rotar) { $argumentos += '--force' }
    } elseif ($Rotar) {
        Detener 'No hay una clave pública vigente que rotar: crea el primer par sin -RotarClaves.'
    }

    $contrasena = $env:RELEASE_KEY_PASSPHRASE
    if (-not $contrasena) {
        Write-Host '  La clave privada se protege con una contraseña (mínimo 12 caracteres). Guárdala en un gestor de contraseñas: sin ella la clave no sirve.'
        while ($true) {
            $contrasena = Leer-Contrasena 'Contraseña'
            if ($contrasena.Length -lt 12) { Fallo 'Debe tener al menos 12 caracteres.'; continue }
            if ($contrasena -ne (Leer-Contrasena 'Repite la contraseña')) { Fallo 'No coinciden.'; continue }
            break
        }
    }

    $env:RELEASE_KEY_PASSPHRASE = $contrasena
    try {
        $global:LASTEXITCODE = 0
        $salida = php @argumentos
        $codigo = $LASTEXITCODE
    } finally { Remove-Item Env:RELEASE_KEY_PASSPHRASE -ErrorAction SilentlyContinue }

    $salida | Out-Host
    if ($codigo -ne 0) { Detener 'No se pudo crear el par de claves.' }

    $ruta = [regex]::Match(($salida | Out-String), 'release:use-key "(?<ruta>[^"]+)"').Groups['ruta'].Value
    if (-not $ruta) { Detener 'No se pudo leer la ruta de la clave recién creada.' }

    if ($Rotar) {
        Mostrar-ResguardoDeClaves $ruta
        Write-Host ''
        Aviso 'Rotación en curso: la clave ACTUAL sigue firmando. Pasos:'
        Write-Host '     1. Haz commit de config\release-public-keys\ (la pública nueva viaja en el código).'
        Write-Host '     2. Publica una versión con este script: se firma con la clave actual y los servidores pasan a confiar también en la nueva.'
        Write-Host "     3. Cuando todos la instalaron, cambia a la nueva: php artisan release:use-key `"$ruta`""
        exit 0
    }

    Registrar-Clave $ruta
    Mostrar-ResguardoDeClaves $ruta

    Write-Host ''
    Aviso 'Para terminar: haz commit de config\release-public.key (la pública sí va al repositorio) y vuelve a ejecutar este script.'
    exit 0
}

function Probar-ClaveFirma {
    Titulo 'Clave de firma'

    $ruta = Obtener-RutaClaveFirma
    $publica = Join-Path $Raiz 'config\release-public.key'

    if ($ruta -and (Test-Path -LiteralPath $ruta) -and (Test-Path $publica)) {
        $global:LASTEXITCODE = 0
        php artisan release:keycheck | Out-Host
        if ($LASTEXITCODE -eq 0) {
            Pedir-ContrasenaFirma $ruta
            return
        }
        Aviso 'La clave de firma configurada no pasa la revisión.'
    } else {
        Aviso 'Todavía no hay un par de claves para firmar versiones.'
    }

    Write-Host '  1) Crear un par de claves nuevo (fuera del proyecto, protegido con contraseña)'
    Write-Host '  2) Usar un archivo de clave privada que ya tengo'
    Write-Host '  3) Cancelar'
    switch ((Read-Host '  Elige 1, 2 o 3').Trim()) {
        '1' { Crear-ParDeClaves }
        '2' {
            $existente = (Read-Host '  Ruta del archivo de clave privada').Trim().Trim('"')
            if (-not (Test-Path -LiteralPath $existente)) { Detener "No existe $existente." }
            Registrar-Clave (Resolve-Path -LiteralPath $existente).Path
            Pedir-ContrasenaFirma (Resolve-Path -LiteralPath $existente).Path
        }
        default { Detener 'Sin clave de firma no se pueden publicar paquetes.' }
    }
}

# ---------------------------------------------------------------------------------------------------------------
# 2. Verificaciones
# ---------------------------------------------------------------------------------------------------------------
function Ejecutar-Verificaciones {
    Titulo '2. Verificaciones'

    $comprobaciones = @(
        @{ Nombre = 'Formato del código (Pint)'; Bloque = { php vendor/bin/pint --test }; Omitible = $false },
        @{ Nombre = 'Pruebas automáticas'; Bloque = { php artisan test --compact }; Omitible = $false },
        @{ Nombre = 'Vulnerabilidades en Composer (producción)'; Bloque = { composer audit --no-dev }; Omitible = $true },
        @{ Nombre = 'Vulnerabilidades en npm (producción)'; Bloque = { npm audit --omit=dev }; Omitible = $true },
        @{ Nombre = 'Compilación de assets (npm run build)'; Bloque = { npm run build }; Omitible = $false }
    )

    $obligatoriasFallidas = @()

    foreach ($comprobacion in $comprobaciones) {
        Write-Host "  > $($comprobacion.Nombre)" -ForegroundColor DarkGray
        $global:LASTEXITCODE = 0
        & $comprobacion.Bloque
        if ($LASTEXITCODE -eq 0) {
            Ok $comprobacion.Nombre
            $Resumen.Add("OK     $($comprobacion.Nombre)")
            continue
        }

        Fallo "$($comprobacion.Nombre) (código $LASTEXITCODE)"
        if ($comprobacion.Omitible -and (Confirmar 'Es una auditoría de dependencias. ¿Publicar de todos modos bajo tu responsabilidad?' $false)) {
            $Resumen.Add("AVISO  $($comprobacion.Nombre): publicada con la advertencia")
        } else {
            $Resumen.Add("FALLA  $($comprobacion.Nombre)")
            $obligatoriasFallidas += $comprobacion.Nombre
        }
    }

    Write-Host '  > Configuración de seguridad (informativo; la revisión estricta se hace con el .env de producción)' -ForegroundColor DarkGray
    php artisan security:check

    if ($obligatoriasFallidas.Count -gt 0) {
        Detener ("No se publica con verificaciones fallidas: " + ($obligatoriasFallidas -join '; '))
    }
}

# ---------------------------------------------------------------------------------------------------------------
# 3 y 4. Versión y CHANGELOG
# ---------------------------------------------------------------------------------------------------------------
function Elegir-Tipo {
    if ($Tipo) { return $Tipo }

    Write-Host '  ¿Qué tipo de cambio es?'
    Write-Host '     1) patch  corrección de errores'
    Write-Host '     2) minor  función nueva compatible'
    Write-Host '     3) major  cambio que rompe compatibilidad (API, formato de datos)'
    Write-Host "     4) sin cambio de versión: generar o regenerar el paquete de la versión actual ($versionActual)"
    while ($true) {
        switch ((Read-Host '  Elige 1, 2, 3 o 4').Trim()) {
            '1' { return 'patch' }
            '2' { return 'minor' }
            '3' { return 'major' }
            '4' { return 'regenerar' }
        }
    }
}

function Elegir-Modo {
    if ($Modo) { return $Modo }

    Write-Host '  Se publicará con ambos: rama de producción (git pull) y paquete ZIP firmado en partes de 9 MiB.'
    Write-Host '  (Para uno solo: -Modo git o -Modo paquete.)'

    return 'ambos'
}

function Construir-SeccionCambios([string]$Version, [string]$EtiquetaAnterior) {
    $rango = if ($EtiquetaAnterior) { "$EtiquetaAnterior..HEAD" } else { 'HEAD' }
    $asuntos = @(git log $rango --no-merges --pretty=format:%s)

    $secciones = [ordered]@{ 'Añadido' = @(); 'Cambiado' = @(); 'Corregido' = @(); 'Seguridad' = @() }

    foreach ($asunto in $asuntos) {
        if (-not $asunto) { continue }
        $tipo = ''
        $ambito = ''
        $mensaje = $asunto

        if ($asunto -match '^(?<t>[A-Za-z]+)(\((?<a>[^)]*)\))?!?:\s*(?<m>.+)$') {
            $tipo = ([string]$Matches['t']).ToLower()
            $ambito = ([string]$Matches['a']).ToLower()
            $mensaje = $Matches['m']
        }

        $mensaje = $mensaje.Trim()
        if ($mensaje.Length -gt 200) { $mensaje = $mensaje.Substring(0, 197) + '...' }
        $mensaje = $mensaje.Substring(0, 1).ToUpper() + $mensaje.Substring(1)

        $destino = 'Cambiado'
        if ($ambito -eq 'security' -or $tipo -in @('security', 'sec')) { $destino = 'Seguridad' }
        elseif ($tipo -eq 'feat') { $destino = 'Añadido' }
        elseif ($tipo -eq 'fix') { $destino = 'Corregido' }

        $secciones[$destino] += $mensaje
    }

    $texto = "## [$Version] - $(Get-Date -Format 'yyyy-MM-dd')`n"
    $hayCambios = $false
    foreach ($nombre in $secciones.Keys) {
        if ($secciones[$nombre].Count -eq 0) { continue }
        $hayCambios = $true
        $texto += "`n### $nombre`n"
        foreach ($linea in $secciones[$nombre]) { $texto += "- $linea`n" }
    }
    if (-not $hayCambios) { $texto += "`n- Sin cambios registrados.`n" }

    return $texto
}

function Actualizar-Changelog([string]$Seccion) {
    $ruta = Join-Path $Raiz 'CHANGELOG.md'
    $encabezado = "# Registro de cambios`n`nTodos los cambios relevantes de la aplicación. Formato basado en Keep a Changelog; las versiones siguen SemVer (MAYOR.MENOR.PARCHE).`n`n"

    if (-not (Test-Path $ruta)) {
        [System.IO.File]::WriteAllText($ruta, $encabezado + $Seccion, $Utf8SinBom)
        return
    }

    $actual = [System.IO.File]::ReadAllText($ruta).Replace("`r`n", "`n")
    $posicion = $actual.IndexOf("`n## [")
    if ($posicion -ge 0) {
        $nuevo = $actual.Substring(0, $posicion + 1) + $Seccion + "`n" + $actual.Substring($posicion + 1)
    } else {
        $nuevo = $actual.TrimEnd() + "`n`n" + $Seccion
    }
    [System.IO.File]::WriteAllText($ruta, $nuevo, $Utf8SinBom)
}

# ---------------------------------------------------------------------------------------------------------------
# 5, 6 y 7. Commit, paquete y rama de producción
# ---------------------------------------------------------------------------------------------------------------
function Crear-Paquete([string]$Version, [string]$Etiqueta, [string]$Anterior, [string]$Commit, [int]$Build = 1) {
    Titulo '6. Paquete firmado'

    $staging = Join-Path $env:TEMP "proy03-staging-$Version"
    $zip = Join-Path $env:TEMP "proy03-staging-$Version.zip"
    $salida = Join-Path $Raiz 'releases'

    try {
        foreach ($ruta in @($staging, $zip)) { if (Test-Path $ruta) { Remove-Item $ruta -Recurse -Force } }

        Ejecutar "Exportar el código de $Etiqueta (git archive)" { git archive --format=zip --output="$zip" $Etiqueta }
        Expand-Archive -Path $zip -DestinationPath $staging -Force
        Ok 'Código exportado desde la etiqueta, sin archivos sin versionar ni secretos'

        Push-Location $staging
        try {
            Ejecutar 'Instalar dependencias de producción (composer install --no-dev)' { composer install --no-dev --optimize-autoloader --no-interaction --no-scripts }
        } finally { Pop-Location }

        $assets = Join-Path $Raiz 'public\build'
        if (-not (Test-Path (Join-Path $assets 'manifest.json'))) { Detener 'No existe public\build\manifest.json: falló la compilación de assets.' }
        Copy-Item $assets (Join-Path $staging 'public\build') -Recurse -Force
        Ok 'Assets compilados incluidos'

        $argumentos = @('artisan', 'release:package', $Version, "--source=$staging", "--output=$salida", "--commit=$Commit", "--build=$Build")
        if ($Anterior) { $argumentos += "--previous=$Anterior" }
        # La contraseña de la clave solo existe en el entorno de este proceso mientras se firma.
        if ($script:ContrasenaFirma) { $env:RELEASE_KEY_PASSPHRASE = $script:ContrasenaFirma }
        try {
            Ejecutar 'php artisan release:package (ZIP en partes + manifiesto firmado + verificación)' { php @argumentos }
        } finally { Remove-Item Env:RELEASE_KEY_PASSPHRASE -ErrorAction SilentlyContinue }

        $carpeta = Join-Path $salida "v$Version"
        Ejecutar 'php artisan release:verify (segunda verificación independiente)' { php artisan release:verify $carpeta }

        $manifiesto = Get-Content (Join-Path $carpeta 'manifest.json') -Raw | ConvertFrom-Json
        Add-Content -Path (Join-Path $salida 'historial.log') -Value ("{0}  v{1}  build {2}  commit {3}  {4} parte(s)  sha256 {5}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Version, $Build, $Commit, $manifiesto.parts.Count, $manifiesto.package.sha256)

        $Resumen.Add("OK     Paquete firmado: releases\v$Version, build $Build ($($manifiesto.parts.Count) parte(s))")
        return $carpeta
    } finally {
        foreach ($ruta in @($staging, $zip)) { if (Test-Path $ruta) { Remove-Item $ruta -Recurse -Force -ErrorAction SilentlyContinue } }
    }
}

function Publicar-RamaProduccion([string]$Version, [string]$Etiqueta) {
    Titulo '7. Rama de producción'

    $trabajo = Join-Path $env:TEMP "proy03-produccion-$Version"
    if (Test-Path $trabajo) { Remove-Item $trabajo -Recurse -Force }

    $existeLocal = Salida { git branch --list $RamaProduccion }
    $existeRemota = Salida { git ls-remote --heads $Remoto $RamaProduccion }

    if (-not $existeLocal) {
        if ($existeRemota) {
            Ejecutar "Traer $RamaProduccion desde $Remoto" { git fetch $Remoto "${RamaProduccion}:${RamaProduccion}" }
        } else {
            Aviso "La rama $RamaProduccion no existe todavía: se crea a partir de $Etiqueta."
            Ejecutar "Crear la rama $RamaProduccion" { git branch $RamaProduccion $Etiqueta }
        }
    }

    Ejecutar 'Preparar una copia de trabajo temporal de la rama' { git worktree add $trabajo $RamaProduccion }

    try {
        Push-Location $trabajo
        try {
            Ejecutar "Fusionar $Etiqueta en $RamaProduccion" { git merge --no-ff $Etiqueta -m "release: v$Version" }

            $destino = Join-Path $trabajo 'public\build'
            if (Test-Path $destino) { Remove-Item $destino -Recurse -Force }
            Copy-Item (Join-Path $Raiz 'public\build') $destino -Recurse
            git add -f -A public/build

            git diff --cached --quiet
            if ($LASTEXITCODE -ne 0) {
                Ejecutar 'Guardar los assets compilados en la rama' { git commit -m "build: assets compilados de v$Version" }
            }
        } finally { Pop-Location }

        Ok "$RamaProduccion contiene v$Version con los assets compilados"
        Write-Host ''
        Write-Host "  Se subirá a ${Remoto}: la rama $RamaProduccion y la etiqueta $Etiqueta."
        if (Confirmar '¿Subir ahora?' $true) {
            Ejecutar "git push $Remoto $RamaProduccion" { git push $Remoto $RamaProduccion }
            Ejecutar "git push $Remoto $Etiqueta" { git push $Remoto $Etiqueta }
            $Resumen.Add("OK     Rama $RamaProduccion y etiqueta $Etiqueta subidas a $Remoto")
        } else {
            $Resumen.Add("AVISO  $RamaProduccion preparada pero NO subida (git push $Remoto $RamaProduccion; git push $Remoto $Etiqueta)")
        }
    } finally {
        git worktree remove --force $trabajo
        git worktree prune
    }
}

# ---------------------------------------------------------------------------------------------------------------
# Regenerar la versión actual (sin incrementar)
# ---------------------------------------------------------------------------------------------------------------
function Regenerar-Release([string]$Version) {
    Titulo "Regenerar la versión $Version (sin cambiar el número)"

    $etiqueta = "v$Version"
    $etiquetaNueva = $false
    git rev-parse -q --verify "refs/tags/$etiqueta" *> $null
    if ($LASTEXITCODE -ne 0) {
        $commitHead = Salida { git rev-parse --short HEAD }
        Aviso "La etiqueta $etiqueta no existe: es la primera vez que se genera esta versión."
        Write-Host "  Se creará en el commit actual ($commitHead), que ya trae VERSION $Version."
        if (-not (Select-String -Path (Join-Path $Raiz 'CHANGELOG.md') -Pattern ([regex]::Escape("## [$Version]")) -Quiet)) {
            Aviso "CHANGELOG.md no tiene una sección para $Version."
        }
        if (-not (Confirmar "¿Crear la etiqueta $etiqueta en el commit $commitHead?" $true)) { Detener 'Cancelado: no se cambió nada.' }
        Ejecutar "git tag $etiqueta" { git tag -a $etiqueta -m "Versión $Version" }
        $etiquetaNueva = $true
    }

    $commitEtiqueta = Salida { git rev-parse --short "$etiqueta^{commit}" }
    $commitActual = Salida { git rev-parse --short HEAD }
    if ($commitEtiqueta -ne $commitActual) {
        Aviso "Hay commits posteriores a $etiqueta ($commitEtiqueta); HEAD está en $commitActual."
        Write-Host '  El paquete se arma con el código de la etiqueta, así que esos commits no se incluyen.'
        Write-Host '  Para publicarlos elige patch, minor o major.'
        if (-not (Confirmar '¿Regenerar igualmente el paquete de la etiqueta?' $false)) { Detener 'Cancelado: no se cambió nada.' }
    }

    $anterior = Salida { git describe --tags --abbrev=0 --match 'v*' "$etiqueta^" 2>$null }
    $anterior = if ($anterior) { $anterior.TrimStart('v') } else { $null }

    $carpeta = Join-Path (Join-Path $Raiz 'releases') $etiqueta
    $respaldo = $null

    if (Test-Path $carpeta) {
        $respaldo = "$carpeta.anterior-" + (Get-Date -Format 'yyyyMMdd-HHmmss')
        Write-Host "  Ya existe releases\$etiqueta; se conservará como releases\$(Split-Path $respaldo -Leaf)."
    }
    if (-not (Confirmar "¿Generar de nuevo el paquete firmado de $etiqueta?" $true)) { Detener 'Cancelado: no se cambió nada.' }

    $build = 1
    if ($respaldo) {
        $manifiestoAnterior = Join-Path $carpeta 'manifest.json'
        if (Test-Path $manifiestoAnterior) {
            $datos = Get-Content $manifiestoAnterior -Raw | ConvertFrom-Json
            $buildAnterior = if ($datos.build) { [int]$datos.build } else { 1 }
            $build = $buildAnterior + 1
        }
        Rename-Item -Path $carpeta -NewName (Split-Path $respaldo -Leaf)
    }
    Write-Host "  Número de build del paquete nuevo: $build"

    try {
        $nueva = Crear-Paquete $Version $etiqueta $anterior $commitEtiqueta $build
    } catch {
        if ($etiquetaNueva) {
            git tag -d $etiqueta *> $null
            Aviso "Se eliminó la etiqueta $etiqueta recién creada para poder volver a intentarlo."
        }
        if (Test-Path $carpeta) { Remove-Item $carpeta -Recurse -Force -ErrorAction SilentlyContinue }
        if ($respaldo -and (Test-Path $respaldo)) {
            Rename-Item -Path $respaldo -NewName (Split-Path $carpeta -Leaf)
            Aviso "La regeneración falló: se restauró releases\$etiqueta tal como estaba."
        }
        throw
    }

    Titulo 'Resumen'
    $Resumen | ForEach-Object { Write-Host "  $_" }
    if ($respaldo) { Write-Host "  OK     Paquete anterior conservado en releases\$(Split-Path $respaldo -Leaf)" }
    Write-Host ''
    Write-Host "Paquete de $etiqueta regenerado (misma versión, mismo código)." -ForegroundColor Green
    Write-Host "  Sube el contenido de: $nueva"
    Write-Host '  (todas las partes .001, .002, ... más manifest.json y manifest.json.sig).'
    if ($etiquetaNueva) {
        Write-Host "  La etiqueta $etiqueta es solo local: para subirla, git push $Remoto $etiqueta"
    }
}

# ---------------------------------------------------------------------------------------------------------------
# Programa principal
# ---------------------------------------------------------------------------------------------------------------
$commitCreado = $null
$etiquetaCreada = $null

try {
    Write-Host ''
    Write-Host 'Preparación de una versión nueva' -ForegroundColor White

    Probar-Requisitos

    if ($SoloVerificar) {
        Ejecutar-Verificaciones
        Write-Host ''
        Ok 'Todas las verificaciones pasaron.'
        exit 0
    }

    if ($RotarClaves) {
        Titulo 'Rotación de claves'
        Crear-ParDeClaves -Rotar
    }

    Probar-ClaveFirma
    Ejecutar-Verificaciones

    $versionActual = (Get-Content (Join-Path $Raiz 'VERSION') -Raw).Trim()
    Titulo "3. Versión actual $versionActual"
    $tipoElegido = Elegir-Tipo

    if ($tipoElegido -eq 'regenerar') {
        Regenerar-Release $versionActual
        exit 0
    }

    $modoElegido = Elegir-Modo

    $version = Salida { php artisan release:next $tipoElegido }
    if (-not $version) { Detener 'No se pudo calcular la versión siguiente.' }
    $etiqueta = "v$version"

    git rev-parse -q --verify "refs/tags/$etiqueta" *> $null
    if ($LASTEXITCODE -eq 0) { Detener "La etiqueta $etiqueta ya existe." }

    $etiquetaAnterior = Salida { git describe --tags --abbrev=0 --match 'v*' 2>$null }
    Ok "Versión actual $versionActual -> nueva $version ($tipoElegido)"

    Titulo '4. Registro de cambios'
    $seccion = Construir-SeccionCambios $version $etiquetaAnterior
    Write-Host ''
    $seccion -split "`n" | ForEach-Object { Write-Host "     $_" }
    Actualizar-Changelog $seccion
    Ok 'CHANGELOG.md actualizado'
    Write-Host '  Puedes abrir CHANGELOG.md y ajustar el texto antes de continuar.'
    [void](Read-Host '  Pulsa Enter para continuar')

    Titulo '5. Commit y etiqueta'
    if (-not (Confirmar "¿Crear el commit «chore(release): $etiqueta» y la etiqueta $etiqueta?" $true)) {
        git checkout -- CHANGELOG.md 2>$null
        Detener 'Cancelado: no se creó ningún commit.'
    }
    Ejecutar 'Escribir VERSION' { php artisan release:next $tipoElegido --write | Out-Null }
    Ejecutar 'git add' { git add VERSION CHANGELOG.md }
    Ejecutar 'git commit' { git commit -m "chore(release): $etiqueta" }
    $commitCreado = Salida { git rev-parse HEAD }
    Ejecutar 'git tag' { git tag -a $etiqueta -m "Versión $version" }
    $etiquetaCreada = $etiqueta
    $commitCorto = Salida { git rev-parse --short HEAD }
    $Resumen.Add("OK     Commit $commitCorto y etiqueta $etiqueta creados")

    $carpetaPaquete = $null
    if ($modoElegido -in @('paquete', 'ambos')) {
        $anterior = if ($etiquetaAnterior) { $etiquetaAnterior.TrimStart('v') } else { $null }
        $carpetaPaquete = Crear-Paquete $version $etiqueta $anterior $commitCorto
    }

    if ($modoElegido -in @('git', 'ambos')) {
        if (-not (Test-Path (Join-Path $Raiz 'public\build\manifest.json'))) { Detener 'Falta public\build: ejecuta npm run build.' }
        Publicar-RamaProduccion $version $etiqueta
    }

    Titulo 'Resumen'
    $Resumen | ForEach-Object { Write-Host "  $_" }

    Write-Host ''
    Write-Host "Versión $version lista." -ForegroundColor Green
    if ($carpetaPaquete) {
        Write-Host "  Para el hosting sin SSH, sube el contenido de: $carpetaPaquete"
        Write-Host '  (todas las partes .001, .002, ... más manifest.json y manifest.json.sig).'
    }
    Write-Host '  Después de instalarla comprueba /health con la cabecera X-Health-Token y ejecuta php artisan security:check --strict en el servidor.'
    Write-Host "  No olvides subir la rama $RamaBase si todavía no lo hiciste: git push $Remoto $RamaBase"
    exit 0
} catch {
    Write-Host ''
    Fallo $_.Exception.Message

    if ($commitCreado -and (Salida { git rev-parse HEAD }) -eq $commitCreado) {
        Write-Host ''
        Aviso "El commit y la etiqueta $etiquetaCreada ya se crearon en tu repositorio local, pero la versión no se completó."
        if (Confirmar '¿Deshacerlos para poder volver a intentarlo?' $true) {
            git tag -d $etiquetaCreada | Out-Null
            git reset --hard HEAD~1 | Out-Null
            Ok 'Commit y etiqueta deshechos; el repositorio quedó como antes.'
        } else {
            Aviso 'Se conservaron. Para deshacerlos a mano: git tag -d <etiqueta>; git reset --hard HEAD~1'
        }
    }

    if ($Resumen.Count -gt 0) {
        Write-Host ''
        $Resumen | ForEach-Object { Write-Host "  $_" }
    }
    exit 1
}
