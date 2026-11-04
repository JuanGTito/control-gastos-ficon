param(
    [int]$DatabasePort = 3307,
    [string]$MySqlBin = 'C:\Program Files\MySQL\MySQL Server 8.0\bin'
)
$ErrorActionPreference = 'Stop'
$projectDir = Split-Path $PSScriptRoot -Parent
Set-Location -LiteralPath $projectDir
$runtimeDir = Join-Path $projectDir '.runtime'
New-Item -ItemType Directory -Path $runtimeDir -Force | Out-Null
$serverExe = Join-Path $MySqlBin 'mysqld.exe'
$clientExe = Join-Path $MySqlBin 'mysql.exe'
if (-not (Test-Path -LiteralPath $serverExe) -or -not (Test-Path -LiteralPath $clientExe)) {
    throw 'Instala MySQL Community Server 8.0 o indica -MySqlBin con la carpeta bin.'
}
$credentialPath = Join-Path $runtimeDir 'mysql-credentials.json'
$dataDir = Join-Path $runtimeDir 'mysql-data'
$iniPath = Join-Path $runtimeDir 'mysql.ini'
$fresh = -not (Test-Path -LiteralPath $credentialPath)
if ($fresh) {
    if (Test-Path -LiteralPath (Join-Path $dataDir 'auto.cnf')) { throw 'Hay datos MySQL sin credenciales del proyecto. No se reinicializan.' }
    if (Get-NetTCPConnection -LocalPort $DatabasePort -State Listen -ErrorAction SilentlyContinue) { throw "El puerto MySQL $DatabasePort está ocupado." }
    New-Item -ItemType Directory -Path $dataDir -Force | Out-Null
    $credentials = @{ root = ([guid]::NewGuid().ToString('N') + 'Aa9!'); app = ([guid]::NewGuid().ToString('N') + 'Bb8!'); port = $DatabasePort }
    $credentials | ConvertTo-Json | Set-Content -LiteralPath $credentialPath -Encoding ascii
    $baseDir = Split-Path $MySqlBin -Parent
    $configuration = "[mysqld]`nbasedir=$($baseDir.Replace('\','/'))`ndatadir=$($dataDir.Replace('\','/'))`nport=$DatabasePort`nbind-address=127.0.0.1`nlog-error=$((Join-Path $runtimeDir 'mysql-error.log').Replace('\','/'))`ncharacter-set-server=utf8mb4`ncollation-server=utf8mb4_bin`n[client]`nport=$DatabasePort`nhost=127.0.0.1`n"
    [IO.File]::WriteAllText($iniPath, $configuration)
    & $serverExe '--no-defaults' '--initialize-insecure' "--basedir=$baseDir" "--datadir=$dataDir" '--console'
    if ($LASTEXITCODE -ne 0) { throw 'Falló la inicialización de MySQL.' }
} else {
    $credentials = Get-Content -LiteralPath $credentialPath -Raw | ConvertFrom-Json
    $DatabasePort = [int]$credentials.port
    if (-not (Test-Path -LiteralPath $iniPath) -or -not (Test-Path -LiteralPath (Join-Path $dataDir 'auto.cnf'))) {
        throw 'Faltan los archivos de la instancia MySQL del proyecto.'
    }
}
$listener = Get-NetTCPConnection -LocalPort $DatabasePort -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if ($listener) {
    $owner = Get-CimInstance Win32_Process -Filter "ProcessId = $($listener.OwningProcess)"
    if ($owner.CommandLine -notlike '*mysql.ini*') { throw "El puerto $DatabasePort pertenece a otra instancia MySQL." }
} else {
    $server = Start-Process -FilePath $serverExe -ArgumentList @("--defaults-file=`"$iniPath`"") -WorkingDirectory $projectDir -WindowStyle Hidden -PassThru
    [IO.File]::WriteAllText((Join-Path $runtimeDir 'mysql.pid'), [string]$server.Id)
    $ready = $false
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        Start-Sleep -Milliseconds 500
        if (Get-NetTCPConnection -LocalPort $DatabasePort -State Listen -ErrorAction SilentlyContinue) { $ready = $true; break }
    }
    if (-not $ready) { throw 'MySQL no inició. Revisa .runtime/mysql-error.log.' }
}
if ($fresh) {
    $sql = "ALTER USER 'root'@'localhost' IDENTIFIED BY '$($credentials.root)';"
    foreach ($database in @('control_gastos', 'control_gastos_test', 'control_gastos_e2e')) {
        $sql += " CREATE DATABASE $database CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;"
    }
    foreach ($hostName in @('localhost', '127.0.0.1')) {
        $sql += " CREATE USER 'gastos_app'@'$hostName' IDENTIFIED BY '$($credentials.app)';"
        foreach ($database in @('control_gastos', 'control_gastos_test', 'control_gastos_e2e')) {
            $sql += " GRANT ALL PRIVILEGES ON $database.* TO 'gastos_app'@'$hostName';"
        }
    }
    & $clientExe '--protocol=TCP' '--host=127.0.0.1' "--port=$DatabasePort" '--user=root' '--execute' $sql
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo asegurar o crear las bases MySQL.' }
}
$localIniPath = Join-Path $runtimeDir 'php.ini'
if (-not (Test-Path -LiteralPath $localIniPath)) {
    $phpIniSource = & php -r 'echo php_ini_loaded_file();'
    $phpConfig = Get-Content -LiteralPath $phpIniSource -Raw
    if ($phpConfig -notmatch '(?m)^extension\s*=\s*(php_)?pdo_mysql') { $phpConfig += "`nextension=pdo_mysql`n" }
    $phpConfig += "`nupload_max_filesize=5M`npost_max_size=8M`nmemory_limit=512M`n"
    [IO.File]::WriteAllText($localIniPath, $phpConfig)
}
if (-not (& php -c $localIniPath -m | Where-Object { $_ -eq 'pdo_mysql' })) {
    throw 'PHP no tiene pdo_mysql habilitado.'
}
if (-not (Test-Path -LiteralPath '.env')) { Copy-Item -LiteralPath '.env.example' -Destination '.env' }
$envContents = Get-Content -LiteralPath '.env' -Raw
if ($envContents -match '(?m)^DB_CONNECTION=pgsql\s*$') {
    throw 'Esta instalación aún apunta a PostgreSQL. Migra y verifica los datos antes de cambiarla a MySQL.'
}
$values = @{ APP_NAME = '"Gastos Ficon"'; APP_LOCALE = 'es'; APP_URL = 'http://localhost:8000'; DB_CONNECTION = 'mysql'; DB_HOST = '127.0.0.1'; DB_PORT = $DatabasePort; DB_DATABASE = 'control_gastos'; DB_USERNAME = 'gastos_app'; DB_PASSWORD = $credentials.app; SESSION_DRIVER = 'database' }
foreach ($key in $values.Keys) {
    if ($envContents -match "(?m)^$key=") { $envContents = [regex]::Replace($envContents, "(?m)^$key=.*$", "$key=$($values[$key])") }
    else { $envContents += "`n$key=$($values[$key])`n" }
}
[IO.File]::WriteAllText((Join-Path $projectDir '.env'), $envContents)
& php -c $localIniPath artisan config:clear
if ($envContents -match '(?m)^APP_KEY=\s*$') { & php -c $localIniPath artisan key:generate }
& php -c $localIniPath artisan migrate --force
if ($LASTEXITCODE -ne 0) { throw 'Fallaron las migraciones MySQL.' }
Write-Host 'MySQL y Gastos Ficon configurados. URL local: http://localhost:8000'
