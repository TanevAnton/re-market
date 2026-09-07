@echo off
REM Double-click this from Explorer, or run it from any folder.
REM %~dp0 is this file's own directory, so no cd is ever needed.
REM -ExecutionPolicy Bypass applies to this one process only - it does not
REM change the machine's policy, which is what makes it safe to leave here.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0dev.ps1"
pause
