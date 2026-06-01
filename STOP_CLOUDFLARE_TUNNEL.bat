@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Get-Process cloudflared -ErrorAction SilentlyContinue | Stop-Process -Force; Remove-Item -LiteralPath '%~dp0cloudflare_tunnel_url.txt' -ErrorAction SilentlyContinue; Write-Host 'Cloudflare tunnel stopped and saved tunnel URL cleared.'"
pause
