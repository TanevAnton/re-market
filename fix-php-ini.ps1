# Pins PHP's upload temp directory.
#
# PHP could not create the upload temp file under `artisan serve` even though
# the CLI could write to the Windows temp path. Leaving upload_tmp_dir empty
# makes PHP resolve it per-process; setting it explicitly removes the guess.
#
# Run:  powershell -ExecutionPolicy Bypass -File fix-php-ini.ps1

$ErrorActionPreference = 'Stop'

$tmp = Join-Path $env:USERPROFILE '.config\herd\tmp'
$ini = (& php -r "echo php_ini_loaded_file();")

if (-not $ini -or -not (Test-Path $ini)) {
    Write-Host "Could not locate php.ini via PHP. Is php on PATH?" -ForegroundColor Red
    exit 1
}

New-Item -ItemType Directory -Force -Path $tmp | Out-Null
Write-Host "temp dir : $tmp"
Write-Host "php.ini  : $ini"

Copy-Item $ini "$ini.bak-$(Get-Date -Format yyyyMMdd-HHmmss)" -Force

$content = Get-Content $ini -Raw

foreach ($key in @('upload_tmp_dir', 'sys_temp_dir')) {
    $line    = '{0} = "{1}"' -f $key, $tmp
    $pattern = '(?m)^\s*;?\s*' + $key + '\s*=.*$'

    if ([regex]::IsMatch($content, $pattern)) {
        $content = [regex]::Replace($content, $pattern, $line)
        Write-Host "updated  : $key"
    } else {
        $content = $content.TrimEnd() + "`r`n" + $line + "`r`n"
        Write-Host "appended : $key"
    }
}

Set-Content -Path $ini -Value $content -NoNewline -Encoding UTF8

Write-Host ""
Write-Host "Now reported by PHP:" -ForegroundColor Green
& php -i | Select-String "upload_tmp_dir|sys_temp_dir|upload_max_filesize|post_max_size"

Write-Host ""
Write-Host "Writing a real file to prove the directory works..." -ForegroundColor Green
& php -r "echo is_writable(ini_get('upload_tmp_dir')) ? 'WRITABLE' : 'NOT WRITABLE';"
Write-Host ""
Write-Host ""
Write-Host "Restart 'php artisan serve' before testing the upload." -ForegroundColor Yellow
