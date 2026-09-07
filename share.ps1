# Put the local site on a public HTTPS URL so someone else can test it.
#
# Uses a Cloudflare quick tunnel: no port forwarding, no firewall rule, no
# router config, works behind CGNAT, and you get real HTTPS. The URL is random
# and lives only as long as this window stays open.
#
#   .\share.ps1
#
# THE THING THAT BREAKS THIS OTHERWISE
# ------------------------------------
# In dev, @vite points the browser at http://localhost:5173 for CSS and JS.
# "localhost" on your visitor's machine is THEIR machine, so they get an
# unstyled page and a console full of failed requests. The fix is to stop
# using the dev server and serve compiled assets instead: build them, and
# delete public/hot, which is the file whose presence tells @vite to use the
# dev server. This script does both.
#
# Cost: no hot reload while sharing. Re-run the script after changing CSS/JS.

param(
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

if (-not (Get-Command cloudflared -ErrorAction SilentlyContinue)) {
    Write-Host "cloudflared is not installed." -ForegroundColor Red
    Write-Host "  winget install --id Cloudflare.cloudflared" -ForegroundColor Yellow
    Write-Host "Then open a new terminal and run this again." -ForegroundColor Yellow
    exit 1
}

# --- do not publish a debug build ------------------------------------------
# With APP_DEBUG=true, any uncaught error renders Laravel's debug page, which
# lists your environment variables - database password and PHONE_HASH_SALT
# included - to whoever triggered it. That is not a hypothetical: a bad URL is
# enough.
$envText = Get-Content '.env' -Raw
if ($envText -match '(?m)^APP_DEBUG\s*=\s*true') {
    Write-Host ""
    Write-Host "APP_DEBUG=true - a stack trace would show your .env to visitors." -ForegroundColor Red
    $answer = Read-Host "Set APP_DEBUG=false for this session? [Y/n]"
    if ($answer -eq '' -or $answer -match '^[Yy]') {
        ($envText -replace '(?m)^APP_DEBUG\s*=\s*true', 'APP_DEBUG=false') |
            Set-Content '.env' -NoNewline
        Write-Host "Set APP_DEBUG=false. Remember to put it back for local work." -ForegroundColor Yellow
    } else {
        Write-Host "Continuing with debug on. Do not leave this running." -ForegroundColor Yellow
    }
}

# --- compiled assets, not the dev server -----------------------------------
Write-Host "Building assets..." -ForegroundColor Cyan
npm run build
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

# public/hot is written by `npm run dev` and is what makes @vite emit
# localhost:5173 URLs. If a dev server is still running, stop it first.
if (Test-Path 'public\hot') {
    Remove-Item 'public\hot' -Force
    Write-Host "Removed public/hot (stop 'npm run dev' if it is still running)." -ForegroundColor Yellow
}

php artisan config:clear | Out-Null
php artisan view:clear   | Out-Null

Write-Host ""
Write-Host "Starting server on port $Port and opening the tunnel..." -ForegroundColor Cyan
Write-Host "Watch for the https://<something>.trycloudflare.com line below." -ForegroundColor DarkGray
Write-Host "Anyone with that link can reach the site. Ctrl+C stops both." -ForegroundColor DarkGray
Write-Host ""

# 127.0.0.1 is deliberate: cloudflared runs on this machine and reaches the
# server locally, so the PHP server never needs to listen on the network and
# Windows Firewall never needs a hole poked in it.
npx concurrently --kill-others --names "php,tunnel" --prefix-colors "blue,yellow" `
    "php artisan serve --port=$Port" `
    "cloudflared tunnel --url http://127.0.0.1:$Port"
