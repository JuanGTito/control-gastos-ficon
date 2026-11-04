$ErrorActionPreference = 'Stop'
$projectDir = Split-Path $PSScriptRoot -Parent
Set-Location -LiteralPath $projectDir
$credentials = Get-Content -LiteralPath '.runtime/mysql-credentials.json' -Raw | ConvertFrom-Json
$testEnv = @{ DB_CONNECTION = 'mysql'; DB_DATABASE = 'control_gastos_test'; DB_HOST = '127.0.0.1'; DB_PORT = $credentials.port; DB_USERNAME = 'gastos_app'; DB_PASSWORD = $credentials.app; PHPRC = (Join-Path $projectDir '.runtime/php.ini') }
$previousEnv = @{}
try {
    foreach ($key in $testEnv.Keys) { $previousEnv[$key] = [Environment]::GetEnvironmentVariable($key, 'Process'); [Environment]::SetEnvironmentVariable($key, [string]$testEnv[$key], 'Process') }
    & php vendor/phpunit/phpunit/phpunit --testdox
    $testExit = $LASTEXITCODE
} finally {
    foreach ($key in $previousEnv.Keys) { [Environment]::SetEnvironmentVariable($key, $previousEnv[$key], 'Process') }
}
exit $testExit
