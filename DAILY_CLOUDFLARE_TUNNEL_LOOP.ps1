$ErrorActionPreference = 'Continue'

$projectDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$starter = Join-Path $projectDir 'START_CLOUDFLARE_DEMO.ps1'
$loopPidFile = Join-Path $projectDir 'cloudflare_daily_loop_pid.txt'
$loopLog = Join-Path $projectDir 'cloudflare_daily_loop.log'
$urlFile = Join-Path $projectDir 'cloudflare_tunnel_url.txt'

Set-Content -LiteralPath $loopPidFile -Value $PID -Encoding ASCII

function Write-LoopLog($text) {
    $line = '{0} {1}' -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $text
    Add-Content -LiteralPath $loopLog -Value $line -Encoding ASCII
}

function Get-CurrentTunnelUrl {
    if (!(Test-Path -LiteralPath $urlFile)) { return $null }
    $text = (Get-Content -LiteralPath $urlFile -Raw -ErrorAction SilentlyContinue).Trim()
    if ($text -match 'https://[a-z0-9-]+\.trycloudflare\.com') { return $matches[0] }
    return $null
}

function Test-TunnelHealth($url) {
    if (!$url) { return $false }
    try {
        $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -TimeoutSec 8 -MaximumRedirection 2
        return ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 500)
    } catch {
        return $false
    }
}

function Refresh-Tunnel($reason) {
    try {
        Write-LoopLog "Refreshing Cloudflare tunnel. Reason: $reason"
        & $starter -Quiet -NoBrowser | Out-File -LiteralPath $loopLog -Append -Encoding ASCII
        if ($LASTEXITCODE -ne $null -and $LASTEXITCODE -ne 0) {
            throw "Starter exited with code $LASTEXITCODE."
        }
        $newUrl = Get-CurrentTunnelUrl
        Write-LoopLog "Active Cloudflare URL: $newUrl"
        return $true
    } catch {
        Write-LoopLog ('Refresh failed: ' + $_.Exception.Message)
        return $false
    }
}

# Start or refresh immediately when the monitor starts.
Refresh-Tunnel 'monitor startup' | Out-Null

while ($true) {
    Start-Sleep -Seconds 60
    $url = Get-CurrentTunnelUrl
    $urlAgeHours = 999
    if (Test-Path -LiteralPath $urlFile) {
        $urlAgeHours = ((Get-Date) - (Get-Item -LiteralPath $urlFile).LastWriteTime).TotalHours
    }

    if (!$url) {
        Refresh-Tunnel 'missing url file' | Out-Null
        continue
    }

    if ($urlAgeHours -ge 6) {
        Refresh-Tunnel ("scheduled renewal after {0:N1} hours" -f $urlAgeHours) | Out-Null
        continue
    }

    if (!(Test-TunnelHealth $url)) {
        Refresh-Tunnel 'health check failed or link expired' | Out-Null
        continue
    }

    Write-LoopLog "Tunnel healthy: $url"
}
