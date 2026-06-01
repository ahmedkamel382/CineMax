param(
    [switch]$Quiet,
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'

$projectDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$htdocs = 'C:\xampp\htdocs'
$xamppControl = 'C:\xampp\xampp-control.exe'
$relativePath = Split-Path -Leaf $projectDir

if ($projectDir.StartsWith($htdocs, [System.StringComparison]::OrdinalIgnoreCase)) {
    $relativePath = $projectDir.Substring($htdocs.Length).TrimStart('\') -replace '\\', '/'
}

$localBase = "http://localhost/$relativePath"
$localApp = "$localBase/index.html?qr=1"
$runStamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$log = Join-Path $projectDir "cloudflared-demo-$runStamp.log"
$errLog = Join-Path $projectDir "cloudflared-demo-$runStamp.err.log"
$urlFile = Join-Path $projectDir 'cloudflare_tunnel_url.txt'
$pidFile = Join-Path $projectDir 'cloudflare_tunnel_pid.txt'

function Write-Section($text) {
    Write-Host ""
    Write-Host "============================================================"
    Write-Host $text
    Write-Host "============================================================"
}

function Find-Cloudflared {
    $candidates = @(
        (Join-Path $projectDir 'tools\cloudflared.exe'),
        (Join-Path $env:USERPROFILE 'cloudflared.exe')
    )

    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            return $candidate
        }
    }

    $cmd = Get-Command cloudflared -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }

    return $null
}

function Stop-PreviousProjectTunnel {
    if (-not (Test-Path -LiteralPath $pidFile)) {
        $oldPidText = ''
    } else {
        $oldPidText = (Get-Content -LiteralPath $pidFile -Raw -ErrorAction SilentlyContinue).Trim()
    }

    $oldPid = 0
    if ([int]::TryParse($oldPidText, [ref]$oldPid)) {
        $oldProcess = Get-Process -Id $oldPid -ErrorAction SilentlyContinue
        if ($oldProcess -and $oldProcess.ProcessName -like 'cloudflared*') {
            Stop-Process -Id $oldPid -Force -ErrorAction SilentlyContinue
            Start-Sleep -Seconds 1
        }
    }

    Remove-Item -LiteralPath $pidFile -Force -ErrorAction SilentlyContinue

    Get-Process -Name cloudflared -ErrorAction SilentlyContinue | ForEach-Object {
        $path = $_.Path
        if ($path -and $path.StartsWith($projectDir, [System.StringComparison]::OrdinalIgnoreCase)) {
            Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue
        }
    }
}

Write-Section 'CINEMAX Cloudflare Public Demo'

if ((-not $Quiet) -and (Test-Path -LiteralPath $xamppControl)) {
    Start-Process -FilePath $xamppControl | Out-Null
    Write-Host 'XAMPP Control Panel opened. Make sure Apache and MySQL are started.'
}

try {
    Invoke-WebRequest -Uri "$localBase/index.html" -UseBasicParsing -TimeoutSec 5 | Out-Null
    Write-Host "Local app is reachable: $localBase/index.html"
} catch {
    Write-Host ""
    Write-Host 'The local app is not reachable yet.'
    Write-Host 'Start Apache in XAMPP, then press Enter to try again.'
    Read-Host | Out-Null
    Invoke-WebRequest -Uri "$localBase/index.html" -UseBasicParsing -TimeoutSec 10 | Out-Null
}

$cloudflared = Find-Cloudflared
if (-not $cloudflared) {
    Write-Host ""
    Write-Host 'cloudflared.exe was not found.'
    Write-Host 'Download it from:'
    Write-Host 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe'
    Write-Host 'Then place it in this project folder under: tools\cloudflared.exe'
    exit 1
}

Stop-PreviousProjectTunnel
if (Test-Path -LiteralPath $urlFile) { Remove-Item -LiteralPath $urlFile -Force }
Get-ChildItem -LiteralPath $projectDir -Filter 'cloudflared-demo-*.log' -ErrorAction SilentlyContinue |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-2) } |
    Remove-Item -Force -ErrorAction SilentlyContinue
Get-ChildItem -LiteralPath $projectDir -Filter 'cloudflared-demo-*.err.log' -ErrorAction SilentlyContinue |
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-2) } |
    Remove-Item -Force -ErrorAction SilentlyContinue

Write-Host "Starting Cloudflare tunnel using: $cloudflared"
$process = Start-Process -FilePath $cloudflared `
    -ArgumentList @('tunnel', '--url', 'http://127.0.0.1:80') `
    -RedirectStandardOutput $log `
    -RedirectStandardError $errLog `
    -WindowStyle Hidden `
    -PassThru

Set-Content -LiteralPath $pidFile -Value $process.Id -Encoding ASCII

$tunnelUrl = $null
for ($i = 0; $i -lt 45; $i++) {
    Start-Sleep -Seconds 1
    $content = ''
    if (Test-Path -LiteralPath $log) { $content += Get-Content -LiteralPath $log -Raw -ErrorAction SilentlyContinue }
    if (Test-Path -LiteralPath $errLog) { $content += Get-Content -LiteralPath $errLog -Raw -ErrorAction SilentlyContinue }

    if ($content -match 'https://[a-z0-9-]+\.trycloudflare\.com') {
        $tunnelUrl = $matches[0]
        break
    }
}

if (-not $tunnelUrl) {
    Write-Host 'Tunnel started, but no public URL was detected yet.'
    Write-Host "Check this log: $errLog"
    Write-Host "Process ID: $($process.Id)"
    exit 1
}

Set-Content -LiteralPath $urlFile -Value $tunnelUrl -Encoding ASCII

$publicApp = "$tunnelUrl/$relativePath/index.html"
$publicQr = "$publicApp?qr=1"

Write-Section 'Public Access Ready'
Write-Host "Tunnel Process ID: $($process.Id)"
Write-Host ""
Write-Host 'Public app link:'
Write-Host $publicApp
Write-Host ""
Write-Host 'QR/display link:'
Write-Host $publicQr
Write-Host ""
Write-Host 'The URL was saved to:'
Write-Host $urlFile
Write-Host ""
Write-Host 'Keep this computer, XAMPP, and the tunnel process running during the discussion.'

try {
    Set-Clipboard -Value $publicApp
    Write-Host 'Public app link copied to clipboard.'
} catch {
    Write-Host 'Could not copy to clipboard automatically.'
}

if (-not $NoBrowser) {
    Start-Process $publicQr
}
