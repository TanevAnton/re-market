# Serve the site on a public port so someone can reach it at http://<your-ip>:2323
#
#   Right-click -> Run with PowerShell, or from an ADMIN terminal:
#     .\expose.ps1
#     .\expose.ps1 -Port 2323 -Minutes 10
#     .\expose.ps1 -Minutes 0        # no time limit
#     .\expose.ps1 -KeepDebug        # leave APP_DEBUG alone (see below)
#
# What it does, and undoes again on exit:
#   - builds assets and removes public/hot, so the page is not styled by a
#     dev server your visitor cannot reach
#   - sets APP_DEBUG=false, restoring your original value afterwards
#   - opens a Windows Firewall rule for this port, deleting it afterwards
#   - stops everything automatically after -Minutes
#
# The one thing it CANNOT do is forward the port on your router. Do that once
# in the router admin page: forward external TCP 2323 to this machine's LAN
# address, same port. The script prints that address.

param(
    [int]$Port = 2323,
    [int]$Minutes = 10,
    [switch]$KeepDebug
)

$ErrorActionPreference = 'Stop'
Set-Location -Path $PSScriptRoot

$isAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)

$ruleName   = "RE-MARKET temp $Port"
$ruleAdded  = $false
$envBackup  = $null
$server     = $null

# --- assets ----------------------------------------------------------------
# In dev, @vite tells the BROWSER to fetch CSS and JS from localhost:5173.
# On your visitor's machine localhost is their machine, so they would get a
# completely unstyled page. Compiled assets are served from your public/ folder
# instead, which they can actually reach. public/hot is the file whose presence
# makes @vite prefer the dev server, so it has to go.
Write-Host "Building assets..." -ForegroundColor Cyan
npm run build
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

if (Test-Path 'public\hot') {
    Remove-Item 'public\hot' -Force
    Write-Host "Removed public/hot - stop 'npm run dev' if it is still running." -ForegroundColor Yellow
}

try {
    # --- debug page --------------------------------------------------------
    # Laravel's error page prints the whole environment - DB_PASSWORD and
    # PHONE_HASH_SALT included - to whoever triggered the error. A mistyped URL
    # is enough. Off by default here; -KeepDebug opts back in.
    if (-not $KeepDebug) {
        $envBackup = Get-Content '.env' -Raw
        ($envBackup -replace '(?m)^APP_DEBUG\s*=\s*true', 'APP_DEBUG=false') |
            Set-Content '.env' -NoNewline
        php artisan config:clear | Out-Null
    }

    php artisan view:clear | Out-Null

    # --- firewall ----------------------------------------------------------
    if ($isAdmin) {
        New-NetFirewallRule -DisplayName $ruleName -Direction Inbound `
            -Protocol TCP -LocalPort $Port -Action Allow -Profile Any | Out-Null
        $ruleAdded = $true
        Write-Host "Firewall opened for TCP $Port (removed on exit)." -ForegroundColor DarkGray
    } else {
        Write-Host ""
        Write-Host "Not running as administrator - the firewall rule was not added." -ForegroundColor Yellow
        Write-Host "Either re-run this in an admin terminal, or add it once by hand:" -ForegroundColor Yellow
        Write-Host "  New-NetFirewallRule -DisplayName '$ruleName' -Direction Inbound -Protocol TCP -LocalPort $Port -Action Allow" -ForegroundColor DarkGray
        Write-Host ""
    }

    # --- addresses ---------------------------------------------------------
    $lan = (Get-NetIPAddress -AddressFamily IPv4 |
        Where-Object { $_.IPAddress -notmatch '^(127\.|169\.254\.)' } |
        Select-Object -First 1).IPAddress

    $wan = try { (Invoke-RestMethod 'https://api.ipify.org' -TimeoutSec 5).Trim() } catch { $null }

    Write-Host ""
    Write-Host "  this machine   http://${lan}:$Port" -ForegroundColor Green
    if ($wan) {
        Write-Host "  send this      http://${wan}:$Port" -ForegroundColor Green
    }
    Write-Host ""
    Write-Host "  Router: forward external TCP $Port to ${lan}:$Port" -ForegroundColor Yellow
    if ($Minutes -gt 0) {
        Write-Host "  Stops automatically in $Minutes minutes. Ctrl+C stops it now." -ForegroundColor DarkGray
    } else {
        Write-Host "  No time limit set. Ctrl+C to stop." -ForegroundColor DarkGray
    }
    Write-Host ""

    # 0.0.0.0 so the server answers on the network interface, not just the
    # loopback it binds to by default.
    $server = Start-Process -FilePath 'php' `
        -ArgumentList 'artisan', 'serve', "--host=0.0.0.0", "--port=$Port" `
        -NoNewWindow -PassThru

    if ($Minutes -gt 0) {
        $deadline = (Get-Date).AddMinutes($Minutes)
        while (-not $server.HasExited -and (Get-Date) -lt $deadline) {
            $left = [int]($deadline - (Get-Date)).TotalSeconds
            Write-Host "`r  live - $([int]($left/60))m $($left%60)s remaining   " -NoNewline -ForegroundColor DarkGray
            Start-Sleep -Seconds 1
        }
        Write-Host ""
    } else {
        $server.WaitForExit()
    }
}
finally {
    # Runs on Ctrl+C and on the timer alike. If the window is killed outright
    # none of this happens - the firewall rule and APP_DEBUG would then need
    # undoing by hand, which is what the names above are for.
    Write-Host ""
    Write-Host "Cleaning up..." -ForegroundColor Cyan

    if ($server -and -not $server.HasExited) {
        Stop-Process -Id $server.Id -Force -ErrorAction SilentlyContinue
        Write-Host "  server stopped" -ForegroundColor DarkGray
    }

    if ($ruleAdded) {
        Remove-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
        Write-Host "  firewall rule removed" -ForegroundColor DarkGray
    }

    if ($envBackup) {
        Set-Content '.env' -Value $envBackup -NoNewline
        php artisan config:clear | Out-Null
        Write-Host "  .env restored (APP_DEBUG back to what it was)" -ForegroundColor DarkGray
    }

    Write-Host ""
    Write-Host "Assets are still the compiled build. Run .\dev.ps1 to get hot reload back." -ForegroundColor Yellow
}
