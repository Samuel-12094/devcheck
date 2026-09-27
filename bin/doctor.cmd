@echo off
REM ============================================================
REM  DevEnv Doctor - rapport en ligne de commande
REM ============================================================
setlocal
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"

"%PHP%" "%~dp0doctor.php" %*
echo.
pause
