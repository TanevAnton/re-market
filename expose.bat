@echo off
REM Double-click to expose the site on port 2323 for 10 minutes.
REM Relaunches itself as administrator so the firewall rule can be added and
REM removed automatically - without that you have to add it by hand once.
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "Start-Process powershell -Verb RunAs -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-NoExit','-File','%~dp0expose.ps1'"
