# Local preview without MySQL or Redis.
#
# Runs the app against a throwaway SQLite database (storage/app/preview.sqlite,
# git-ignored) by overriding a few environment variables for this process only.
# Your .env is not modified. Delete the .sqlite file to start from a fresh seed.
#
#   powershell -ExecutionPolicy Bypass -File scripts/preview.ps1
#   then open http://127.0.0.1:8000  (admin: http://127.0.0.1:8000/bypass-login)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path $PSScriptRoot -Parent)

$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    $php = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages" -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
        Select-Object -First 1 -ExpandProperty FullName
}
if (-not $php) { throw 'php.exe not found. Install PHP 8.2+ and reopen the terminal.' }

$db = Join-Path (Get-Location) 'storage\app\preview.sqlite'
$env:APP_ENV = 'local'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $db
$env:CACHE_STORE = 'file'
$env:SESSION_DRIVER = 'file'
$env:QUEUE_CONNECTION = 'sync'
$env:MAIL_MAILER = 'log'

if (-not (Test-Path $db)) {
    New-Item -ItemType File -Path $db | Out-Null
    & $php artisan migrate --seed --force
    if ($LASTEXITCODE -ne 0) { Remove-Item $db -Force; throw 'Migration/seed failed.' }
} else {
    & $php artisan migrate --force
    # Idempotent: picks up catalogue additions without wiping preview data.
    & $php artisan db:seed --class=Database\Seeders\CatalogSeeder --force
    & $php artisan db:seed --class=Database\Seeders\DistrictSeeder --force
}

& $php artisan cache:clear | Out-Null
$port = if ($env:PORT) { $env:PORT } else { 8000 }
& $php artisan serve --host=127.0.0.1 --port=$port
