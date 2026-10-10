@echo off
powershell -ExecutionPolicy Bypass -File "%~dp0install-f1-race-replay.ps1"
if errorlevel 1 pause
