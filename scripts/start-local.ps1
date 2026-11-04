$ErrorActionPreference = 'Stop'
$projectDir = Split-Path $PSScriptRoot -Parent
Set-Location -LiteralPath $projectDir
& (Join-Path $PSScriptRoot 'setup-local.ps1')
$env:PHPRC = Join-Path $projectDir '.runtime\php.ini'
& php artisan serve --host=localhost --port=8000 --tries=1
