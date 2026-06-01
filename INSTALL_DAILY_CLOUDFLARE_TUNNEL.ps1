$ErrorActionPreference = 'Stop'

$projectDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$starter = Join-Path $projectDir 'START_CLOUDFLARE_DEMO.ps1'
$loop = Join-Path $projectDir 'DAILY_CLOUDFLARE_TUNNEL_LOOP.ps1'
$loopPidFile = Join-Path $projectDir 'cloudflare_daily_loop_pid.txt'
$taskName = 'CINEMAX Daily Cloudflare Tunnel'

if (-not (Test-Path -LiteralPath $starter)) {
    throw "Missing starter script: $starter"
}
if (-not (Test-Path -LiteralPath $loop)) {
    throw "Missing daily loop script: $loop"
}

$powershell = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
$arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$starter`" -Quiet -NoBrowser"

function Stop-ExistingStartupLoop {
    if (-not (Test-Path -LiteralPath $loopPidFile)) {
        return
    }

    $pidText = (Get-Content -LiteralPath $loopPidFile -Raw -ErrorAction SilentlyContinue).Trim()
    $oldPid = 0
    if (-not [int]::TryParse($pidText, [ref]$oldPid)) {
        Remove-Item -LiteralPath $loopPidFile -Force -ErrorAction SilentlyContinue
        return
    }

    $proc = Get-CimInstance Win32_Process -Filter "ProcessId=$oldPid" -ErrorAction SilentlyContinue
    if ($proc -and $proc.CommandLine -like '*DAILY_CLOUDFLARE_TUNNEL_LOOP.ps1*') {
        Stop-Process -Id $oldPid -Force -ErrorAction SilentlyContinue
    }

    Remove-Item -LiteralPath $loopPidFile -Force -ErrorAction SilentlyContinue
}

function Install-StartupShortcut {
    Stop-ExistingStartupLoop

    $startup = [Environment]::GetFolderPath('Startup')
    $shortcutPath = Join-Path $startup 'CINEMAX Daily Cloudflare Tunnel.lnk'
    $shell = New-Object -ComObject WScript.Shell
    $shortcut = $shell.CreateShortcut($shortcutPath)
    $shortcut.TargetPath = $powershell
    $shortcut.Arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$loop`""
    $shortcut.WorkingDirectory = $projectDir
    $shortcut.Description = 'Keeps the CINEMAX Cloudflare quick tunnel refreshed daily.'
    $shortcut.Save()

    Start-Process -FilePath $powershell `
        -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden', '-File', $loop) `
        -WindowStyle Hidden | Out-Null

    Write-Host "Installed Startup shortcut: $shortcutPath"
    Write-Host 'The hidden loop starts at Windows login and refreshes the tunnel every day around 8:00 AM.'
}

$action = New-ScheduledTaskAction -Execute $powershell -Argument $arguments
$dailyTrigger = New-ScheduledTaskTrigger -Daily -At 8:00am
$logonTrigger = New-ScheduledTaskTrigger -AtLogOn
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew

try {
    Register-ScheduledTask `
        -TaskName $taskName `
        -Action $action `
        -Trigger @($dailyTrigger, $logonTrigger) `
        -Settings $settings `
        -Description 'Starts a fresh CINEMAX Cloudflare quick tunnel and updates the public QR link.' `
        -Force | Out-Null

    Write-Host "Installed scheduled task: $taskName"
    Write-Host 'It runs at Windows logon and every day at 8:00 AM.'
    Write-Host ''
    Write-Host 'Starting one fresh tunnel now...'
    & $starter -Quiet -NoBrowser
} catch {
    Write-Host 'Task Scheduler access was denied, so CINEMAX is using the no-admin Startup shortcut method.'
    Install-StartupShortcut
}
