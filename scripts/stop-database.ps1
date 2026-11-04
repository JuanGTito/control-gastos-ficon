$ErrorActionPreference = 'Stop'
$projectDir = Split-Path $PSScriptRoot -Parent
$runtimeDir = Join-Path $projectDir '.runtime'
$credentials = Get-Content -LiteralPath (Join-Path $runtimeDir 'mysql-credentials.json') -Raw | ConvertFrom-Json
$listener = Get-NetTCPConnection -LocalPort $credentials.port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $listener) { Write-Host 'MySQL del proyecto ya está detenido.'; exit 0 }
$owner = Get-CimInstance Win32_Process -Filter "ProcessId = $($listener.OwningProcess)"
if ($owner.CommandLine -notlike '*mysql.ini*') { throw 'El puerto pertenece a otra instancia MySQL; no se detiene.' }
$ini = Get-Content -LiteralPath (Join-Path $runtimeDir 'mysql.ini')
$baseDir = (($ini | Where-Object { $_ -like 'basedir=*' } | Select-Object -First 1) -split '=', 2)[1]
$adminExe = Join-Path $baseDir 'bin\mysqladmin.exe'
$previousPassword = $env:MYSQL_PWD
try {
    $env:MYSQL_PWD = $credentials.root
    & $adminExe '--protocol=TCP' '--host=127.0.0.1' "--port=$($credentials.port)" '--user=root' 'shutdown'
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo detener la instancia MySQL del proyecto.' }
} finally { $env:MYSQL_PWD = $previousPassword }
