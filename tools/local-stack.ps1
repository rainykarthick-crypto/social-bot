<#
.SYNOPSIS
    Start, stop and inspect the social-bot stack natively - no Docker, no services,
    no administrator rights.

.DESCRIPTION
    Runs three things as background processes and records their PIDs under
    storage/run/:

      * MariaDB / MySQL   (mariadbd.exe or mysqld.exe from the runtime directory)
      * the web server    (php -S, serving public/ - dashboard + webhook routes)
      * the queue worker  (php worker.php --daemon)

    The database connection is read from the project .env (DB_HOST/DB_PORT/...),
    so the script never needs its own configuration.

.PARAMETER Action
    start (default), stop, restart or status.

.PARAMETER RuntimeRoot
    Directory holding the portable runtime (php/, mariadb/, bin/composer.phar).
    Defaults to the SOCIAL_BOT_RUNTIME environment variable, or a ".runtime"
    directory next to the project root.

.EXAMPLE
    pwsh -File tools/local-stack.ps1 start
    pwsh -File tools/local-stack.ps1 status
    pwsh -File tools/local-stack.ps1 stop

.NOTES
    The processes are children of this shell. For persistence across logins use
    Task Scheduler (see README section 1.2) or run each command in its own
    terminal window.
#>
[CmdletBinding()]
param(
    [ValidateSet('start', 'stop', 'restart', 'status')]
    [string]$Action = 'start',

    [string]$RuntimeRoot = ''
)

$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$runDir = Join-Path $projectRoot 'storage\run'
$logDir = Join-Path $projectRoot 'storage\logs'
foreach ($dir in $runDir, $logDir) {
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir | Out-Null }
}

function Resolve-RuntimeRoot {
    param([string]$Explicit)

    if ($Explicit) { return (Resolve-Path $Explicit).Path }
    if ($env:SOCIAL_BOT_RUNTIME) { return (Resolve-Path $env:SOCIAL_BOT_RUNTIME).Path }

    $candidate = Join-Path (Split-Path -Parent $projectRoot) '.runtime'
    if (Test-Path $candidate) { return (Resolve-Path $candidate).Path }

    return ''
}

function Resolve-Php {
    param([string]$Root)

    if ($Root) {
        $exe = Join-Path $Root 'php\php.exe'
        if (Test-Path $exe) { return $exe }
    }

    $onPath = Get-Command php -ErrorAction SilentlyContinue
    if ($onPath) { return $onPath.Source }

    throw "php.exe not found. Pass -RuntimeRoot or set SOCIAL_BOT_RUNTIME, or put PHP on PATH."
}

function Resolve-DbServer {
    param([string]$Root)

    if (-not $Root) { return $null }

    foreach ($relative in 'mariadb\*\bin\mariadbd.exe', 'mariadb\*\bin\mysqld.exe', 'mysql\*\bin\mysqld.exe') {
        $found = Get-ChildItem (Join-Path $Root $relative) -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($found) { return $found.FullName }
    }

    return $null
}

function Resolve-DataDir {
    param([string]$Root)

    foreach ($name in 'mariadb-data', 'mysql-data') {
        $candidate = Join-Path $Root $name
        if (Test-Path $candidate) { return $candidate }
    }

    return (Join-Path $Root 'mariadb-data')
}

function Read-EnvValue {
    param([string]$Key, [string]$Default = '')

    $envFile = Join-Path $projectRoot '.env'
    if (-not (Test-Path $envFile)) { return $Default }

    foreach ($line in Get-Content $envFile) {
        $trimmed = $line.Trim()
        if ($trimmed -eq '' -or $trimmed.StartsWith('#')) { continue }
        if ($trimmed -match "^\s*$([regex]::Escape($Key))\s*=\s*(.*)$") {
            return $Matches[1].Trim().Trim('"').Trim("'")
        }
    }

    return $Default
}

function Test-Port {
    # NB: do not name this parameter $Host - that is a read-only automatic variable.
    param([string]$ServerHost, [int]$ServerPort)

    try {
        return (New-Object System.Net.Sockets.TcpClient).ConnectAsync($ServerHost, $ServerPort).Wait(400)
    } catch {
        return $false
    }
}

function Get-RecordedProcess {
    param([string]$Name)

    $pidFile = Join-Path $runDir "$Name.pid"
    if (-not (Test-Path $pidFile)) { return $null }

    $recorded = (Get-Content $pidFile -First 1).Trim()
    if (-not $recorded) { return $null }

    $process = Get-Process -Id ([int]$recorded) -ErrorAction SilentlyContinue
    if ($process) { return $process }

    Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
    return $null
}

function Start-ProcessGroup {
    param([string]$Name, [string]$FilePath, [string[]]$Arguments, [string]$WorkingDirectory)

    $existing = Get-RecordedProcess $Name
    if ($existing) {
        Write-Host ("  {0,-8} already running (pid {1})" -f $Name, $existing.Id) -ForegroundColor DarkGray
        return
    }

    $stdout = Join-Path $logDir "$Name.out.log"
    $stderr = Join-Path $logDir "$Name.err.log"

    $process = Start-Process -FilePath $FilePath -ArgumentList $Arguments -WorkingDirectory $WorkingDirectory `
        -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru -WindowStyle Hidden

    Set-Content -Path (Join-Path $runDir "$Name.pid") -Value $process.Id
    Start-Sleep -Milliseconds 800

    if ($process.HasExited) {
        Write-Host ("  {0,-8} exited immediately - see {1}" -f $Name, $stderr) -ForegroundColor Red
        return
    }

    Write-Host ("  {0,-8} started (pid {1})" -f $Name, $process.Id) -ForegroundColor Green
}

function Stop-ProcessGroup {
    param([string]$Name)

    $process = Get-RecordedProcess $Name
    if (-not $process) {
        Write-Host ("  {0,-8} not running" -f $Name) -ForegroundColor DarkGray
        return
    }

    try {
        Stop-Process -Id $process.Id -Force -ErrorAction Stop
        Write-Host ("  {0,-8} stopped (pid {1})" -f $Name, $process.Id) -ForegroundColor Yellow
    } catch {
        Write-Host ("  {0,-8} could not be stopped: {1}" -f $Name, $_.Exception.Message) -ForegroundColor Red
    }

    Remove-Item (Join-Path $runDir "$Name.pid") -Force -ErrorAction SilentlyContinue
}

function Show-Status {
    param([string]$DbHost, [int]$DbPort, [string]$WebUrl)

    Write-Host "social-bot local stack" -ForegroundColor Cyan
    Write-Host ("  database  {0}:{1}  {2}" -f $DbHost, $DbPort, $(if (Test-Port $DbHost $DbPort) { 'listening' } else { 'DOWN' }))
    Write-Host ("  web       {0}  {1}" -f $WebUrl, $(if (Test-Port '127.0.0.1' ([int]([uri]$WebUrl).Port)) { 'listening' } else { 'DOWN' }))

    foreach ($name in 'mariadb', 'web', 'worker') {
        $process = Get-RecordedProcess $name

        if ($process) {
            Write-Host ("  {0,-8} pid {1}" -f $name, $process.Id)
        } elseif ($name -eq 'mariadb' -and (Test-Port $DbHost $DbPort)) {
            # Started outside this script (service, another shell, MySQL instead of MariaDB).
            Write-Host ("  {0,-8} running (not started by this script)" -f $name) -ForegroundColor DarkGray
        } else {
            Write-Host ("  {0,-8} not running" -f $name)
        }
    }
}

$runtimeRoot = Resolve-RuntimeRoot $RuntimeRoot
$php = Resolve-Php $runtimeRoot
$dbServer = Resolve-DbServer $runtimeRoot
$dataDir = if ($runtimeRoot) { Resolve-DataDir $runtimeRoot } else { '' }

$dbHost = Read-EnvValue 'DB_HOST' '127.0.0.1'
$dbPort = [int](Read-EnvValue 'DB_PORT' '3307')
$webPort = [int](Read-EnvValue 'WEB_PORT' '8081')
$webUrl = "http://127.0.0.1:$webPort"

switch ($Action) {
    'status' {
        Show-Status $dbHost $dbPort $webUrl
        break
    }
    'stop' {
        Write-Host 'Stopping social-bot local stack' -ForegroundColor Cyan
        Stop-ProcessGroup 'worker'
        Stop-ProcessGroup 'web'
        Stop-ProcessGroup 'mariadb'
        break
    }
    { $_ -in 'start', 'restart' } {
        if ($Action -eq 'restart') {
            Write-Host 'Restarting social-bot local stack' -ForegroundColor Cyan
            Stop-ProcessGroup 'worker'
            Stop-ProcessGroup 'web'
            Stop-ProcessGroup 'mariadb'
            Start-Sleep -Seconds 2
        } else {
            Write-Host 'Starting social-bot local stack' -ForegroundColor Cyan
        }

        if (-not (Test-Port $dbHost $dbPort)) {
            if (-not $dbServer) {
                Write-Host '  mariadb  no server binary found - start your database manually' -ForegroundColor Yellow
            } elseif (-not (Test-Path $dataDir)) {
                Write-Host "  mariadb  no data directory at $dataDir - run mysql_install_db.exe first (see README)" -ForegroundColor Yellow
            } else {
                $baseDir = Split-Path -Parent (Split-Path -Parent $dbServer)
                Start-ProcessGroup 'mariadb' $dbServer @(
                    '--no-defaults',
                    "--basedir=$baseDir",
                    "--datadir=$dataDir",
                    "--port=$dbPort",
                    "--bind-address=$dbHost",
                    '--console'
                ) $baseDir

                for ($i = 0; $i -lt 30; $i++) {
                    if (Test-Port $dbHost $dbPort) { break }
                    Start-Sleep -Milliseconds 500
                }

                Write-Host ("  database {0}" -f $(if (Test-Port $dbHost $dbPort) { 'ready' } else { 'did not come up' })) `
                    -ForegroundColor $(if (Test-Port $dbHost $dbPort) { 'Green' } else { 'Red' })
            }
        } else {
            Write-Host ("  database already listening on {0}:{1}" -f $dbHost, $dbPort) -ForegroundColor DarkGray
        }

        Start-ProcessGroup 'web' $php @('-S', "127.0.0.1:$webPort", '-t', 'public', 'public/router.php') $projectRoot
        Start-ProcessGroup 'worker' $php @('worker.php', '--daemon', '--max-runtime=0') $projectRoot

        Write-Host ''
        Write-Host "  dashboard  $webUrl" -ForegroundColor Cyan
        Write-Host "  webhooks   $webUrl/webhook/meta | /whatsapp | /google-ads | /krayin" -ForegroundColor Cyan
        Write-Host "  logs       $logDir" -ForegroundColor DarkGray
        break
    }
}
