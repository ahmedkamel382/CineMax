@echo off
setlocal
set "PROJECT_DIR=%~dp0"
set "XAMPP_CONTROL=C:\xampp\xampp-control.exe"
set "LOCAL_URL=http://localhost/CINEMAWEBAPP_SUBMIT_20260526/CINEMAWEBAPP_SUBMIT_20260526/index.html?qr=1"

echo ============================================================
echo CINEMAX Local Demo
echo ============================================================
echo.
echo 1. Start Apache and MySQL in XAMPP.
echo 2. The browser will open the CINEMAX QR/start page.
echo.

if exist "%XAMPP_CONTROL%" (
  start "" "%XAMPP_CONTROL%"
) else (
  echo XAMPP Control Panel was not found at %XAMPP_CONTROL%.
)

start "" "%LOCAL_URL%"

echo Opened:
echo %LOCAL_URL%
echo.
echo Keep Apache and MySQL running while using the app.
pause
