# RE-MARKET dev server
#
# Starts the PHP server and the Vite asset watcher together, in one window,
# with prefixed output so you can tell which one is talking. Ctrl+C stops both
# - that is what -k does, and without it killing the window leaves an orphaned
# node process holding port 5173 until you hunt it down in Task Manager.
#
# Run it from anywhere: it cds to its own folder first, so you never have to.
#   .\dev.ps1
# Or double-click dev.bat next to it.

param(
    # Change the port when 8000 is taken, or pass -Listen to bind every
    # interface so other machines on your LAN can reach it:
    #   .\dev.ps1 -Port 8080 -Listen
    # -Listen also needs a Windows Firewall rule, and it is LAN only. To let
    # someone outside your network in, use .\share.ps1 instead.
    [int]$Port = 8000,
    [switch]$Listen
)

$ErrorActionPreference = 'Stop'

# $PSScriptRoot is this file's folder, not wherever you happen to be standing.
Set-Location -Path $PSScriptRoot

Write-Host "RE-MARKET  $PSScriptRoot" -ForegroundColor Cyan

# --- the checks that actually cost time when they are wrong ----------------

foreach ($cmd in @('php', 'npm')) {
    if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) {
        Write-Host "'$cmd' is not on PATH." -ForegroundColor Red
        Write-Host "Herd populates ~/.config/herd/bin on its FIRST LAUNCH, not on install." -ForegroundColor Yellow
        Write-Host "Open Herd once, then open a new terminal." -ForegroundColor Yellow
        exit 1
    }
}

if (-not (Test-Path '.env')) {
    Write-Host "No .env file. Copy .env.example, then: php artisan key:generate" -ForegroundColor Red
    exit 1
}

if (-not (Test-Path 'node_modules')) {
    Write-Host "node_modules missing - running npm install..." -ForegroundColor Yellow
    npm install
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
}

# Vite serves assets from memory in dev, so a stale compiled Blade view that
# points at yesterday's build is the classic "my CSS change did nothing".
php artisan view:clear | Out-Null

$bind = if ($Listen) { '0.0.0.0' } else { '127.0.0.1' }

Write-Host ""
Write-Host "  app    http://127.0.0.1:$Port" -ForegroundColor Green
Write-Host "  assets http://127.0.0.1:5173  (Vite, hot reload)" -ForegroundColor Green
if ($Listen) {
    $lan = (Get-NetIPAddress -AddressFamily IPv4 |
        Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' } |
        Select-Object -First 1).IPAddress
    Write-Host "  LAN    http://${lan}:$Port" -ForegroundColor Green
    Write-Host "  Needs a Windows Firewall rule for inbound TCP $Port." -ForegroundColor Yellow
}
Write-Host "  Ctrl+C stops both." -ForegroundColor DarkGray
Write-Host ""

# concurrently is already a devDependency of this project - no global install.
npx concurrently --kill-others --names "php,vite" --prefix-colors "blue,magenta" `
    "php artisan serve --host=$bind --port=$Port" `
    "npm run dev"
