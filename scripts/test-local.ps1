# Corre los tests con el PHP local de Windows (sin Docker).
#
# El php.ini del sistema (Program Files) no trae pdo_sqlite activo y editarlo
# requiere permisos de administrador. Este script genera una copia corregida en
# .php-local/php.ini y la usa solo en este proceso (PHPRC), sin afectar a otros
# PHP instalados (p. ej. los PHP 5.4 de proyectos legacy).
#
# Uso:
#   powershell -ExecutionPolicy Bypass -File scripts/test-local.ps1
#   powershell -ExecutionPolicy Bypass -File scripts/test-local.ps1 --filter BackfillOcFechasTest

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$iniSistema = (& php --ini 2>$null) |
    Where-Object { $_ -match '^Loaded Configuration File:\s*(.+)$' } |
    ForEach-Object { $Matches[1].Trim() } |
    Select-Object -Last 1
if (-not $iniSistema -or -not (Test-Path -LiteralPath $iniSistema)) {
    Write-Error 'No se encontro el php.ini cargado por "php" (revisar que PHP este en el PATH).'
}

$dirLocal = Join-Path $root '.php-local'
New-Item -ItemType Directory -Force -Path $dirLocal | Out-Null
$iniLocal = Join-Path $dirLocal 'php.ini'

$requeridas = @('pdo_sqlite', 'sqlite3', 'gd', 'curl', 'fileinfo', 'intl', 'mbstring', 'openssl', 'pdo_pgsql', 'zip')

$salida = foreach ($linea in (Get-Content -LiteralPath $iniSistema)) {
    $limpia = $linea.Trim()

    # Formato antiguo "extension=php_xxx.dll": duplica el bloque estandar,
    # y php_gd2 / php_xmlrpc no existen en PHP 8.
    if ($limpia -match '^extension\s*=\s*"?php_[a-z0-9_]+\.dll"?\s*$') {
        "; [test-local] $linea"
        continue
    }

    if ($limpia -match '^;\s*extension\s*=\s*([a-z0-9_]+)\s*(;.*)?$' -and $requeridas -contains $Matches[1]) {
        "extension=$($Matches[1])"
        continue
    }

    $linea
}

# Mismos limites que el contenedor (docker/php/uploads.ini); upload_tmp_dir=/tmp no aplica en Windows.
$uploadsIni = Join-Path $root 'docker/php/uploads.ini'
if (Test-Path -LiteralPath $uploadsIni) {
    $salida += ''
    $salida += '; [test-local] docker/php/uploads.ini'
    $salida += (Get-Content -LiteralPath $uploadsIni | Where-Object { $_ -notmatch '^\s*upload_tmp_dir' })
}

# El PHP de Windows no trae bundle de CA: sin esto cURL falla con "SSL certificate problem".
$caBundle = Join-Path $dirLocal 'cacert.pem'
if (-not (Test-Path -LiteralPath $caBundle)) {
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest -UseBasicParsing -Uri 'https://curl.se/ca/cacert.pem' -OutFile $caBundle
    } catch {
        Write-Warning "No se pudo descargar cacert.pem: $($_.Exception.Message)"
    }
}
if (Test-Path -LiteralPath $caBundle) {
    $salida += "curl.cainfo=`"$caBundle`""
    $salida += "openssl.cafile=`"$caBundle`""
}

Set-Content -LiteralPath $iniLocal -Value $salida -Encoding ASCII

$env:PHPRC = $dirLocal

# No se carga .env: traeria URL del sitio par y tickets reales, y los tests llamarian
# servicios en produccion. Solo se inyecta lo que .env.testing no trae.
$bytesClave = New-Object byte[] 32
[System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytesClave)
$env:APP_KEY = 'base64:' + [Convert]::ToBase64String($bytesClave)
$env:APP_URL = 'http://localhost:8082'
$env:APP_ENV = 'testing'

$cargadas = (& php -m 2>$null) | ForEach-Object { $_.Trim().ToLower() }
$faltantes = $requeridas | Where-Object { $cargadas -notcontains $_ }
if ($faltantes) {
    Write-Error "Faltan extensiones en PHP local: $($faltantes -join ', ')"
}

& php artisan config:clear --ansi | Out-Null
& php artisan test @args
exit $LASTEXITCODE
