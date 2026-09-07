# Print the most recent phone-verification code from the log.
#
# In local development LogChannel writes the OTP to storage/logs/laravel.log
# instead of spending money on an SMS. This digs the last one back out.
#
#   .\code.ps1        latest code
#   .\code.ps1 -Watch follow the log and print each new code as it arrives

param([switch]$Watch)

Set-Location -Path $PSScriptRoot

$log = Join-Path $PSScriptRoot 'storage\logs\laravel.log'

if (-not (Test-Path $log)) {
    Write-Host "No log file yet - send a code first." -ForegroundColor Yellow
    exit 1
}

# LogChannel writes: [verification] +359888123456 -> code 123456
$pattern = '\[verification\]\s+(?<phone>\S+)\s+->\s+code\s+(?<code>\d{6})'

if ($Watch) {
    Write-Host "Watching for codes... (Ctrl+C to stop)" -ForegroundColor DarkGray
    Get-Content $log -Tail 0 -Wait | ForEach-Object {
        if ($_ -match $pattern) {
            Write-Host "$($Matches.phone)  $($Matches.code)" -ForegroundColor Green
        }
    }
    exit
}

# Read from the end: the newest code is what you want, and this file only grows.
$hit = Get-Content $log |
    Select-String -Pattern $pattern |
    Select-Object -Last 1

if (-not $hit) {
    Write-Host "No verification codes in the log yet." -ForegroundColor Yellow
    exit 1
}

$m = [regex]::Match($hit.Line, $pattern)

Write-Host ""
Write-Host "  $($m.Groups['phone'].Value)" -ForegroundColor DarkGray
Write-Host "  $($m.Groups['code'].Value)" -ForegroundColor Green
Write-Host ""

# Straight to the clipboard, because the next thing you do is paste it.
$m.Groups['code'].Value | Set-Clipboard
Write-Host "  (copied to clipboard)" -ForegroundColor DarkGray
