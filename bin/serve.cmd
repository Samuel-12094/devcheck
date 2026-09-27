@echo off
REM ============================================================
REM  DevEnv Doctor - serveur web local (independant d'Apache)
REM  Ouvre ensuite http://localhost:8080
REM ============================================================
setlocal
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"

echo.
echo   DevEnv Doctor -> http://localhost:8080
echo   Arret du serveur : Ctrl+C
echo.
"%PHP%" -S localhost:8080 -t "%~dp0.."
