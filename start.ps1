<#
.SYNOPSIS
    Brings the whole Circle stack up and leaves a working app at http://localhost:4321.

.DESCRIPTION
    Idempotent: safe to run again on an already-running stack. It builds the PHP
    image if needed, installs dependencies, migrates and seeds, provisions the
    demo organisation and accounts, waits for the API and the web dev server to
    actually answer, then opens the browser.

.EXAMPLE
    .\start.ps1
.EXAMPLE
    .\start.ps1 -Fresh -Demo      # wipe volumes, rebuild, then run the demo script
#>
[CmdletBinding()]
param(
    [switch]$Fresh,      # destroy existing volumes first (database, object storage, node_modules)
    [switch]$Rebuild,    # force a rebuild of the PHP image
    [switch]$Demo,       # run demo.py (KEEP_OPEN=1) once the stack is healthy
    [switch]$NoBrowser   # skip opening the browser
)

$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

$WebUrl   = 'http://localhost:4321'
$ApiUrl   = 'http://localhost:8000/api'
$MinioUrl = 'http://localhost:19001'
$DemoUser = 'gm@jwamats.test'
$DemoPass = 'correct-horse-battery'

function Step($msg) { Write-Host "`n== $msg" -ForegroundColor Cyan }
function Info($msg) { Write-Host "   $msg" -ForegroundColor DarkGray }
function Die($msg)  { Write-Host "`nX  $msg" -ForegroundColor Red; exit 1 }

function Invoke-Compose {
    param([string[]]$ComposeArgs, [string]$FailMessage)
    & docker compose @ComposeArgs
    if ($LASTEXITCODE -ne 0) { Die $FailMessage }
}

# Returns $true as soon as the URL produces any HTTP response at all — a 401
# from the API is proof it is up just as much as a 200 from the web server.
function Wait-Url {
    param([string]$Url, [int]$TimeoutSeconds = 180, [string]$Label = 'service')

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        try {
            Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 5 | Out-Null
            return $true
        } catch [System.Net.WebException] {
            if ($_.Exception.Response) { return $true }
        } catch {
            if ($_.Exception.Response) { return $true }
        }
        Start-Sleep -Seconds 3
    }
    Die "$Label did not come up within $TimeoutSeconds seconds. Try: docker compose logs"
}

# Native stderr must not be redirected under ErrorActionPreference = Stop, so
# the daemon probe runs with the preference relaxed and reports via exit code.
function Test-DockerUp {
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    & docker info 1>$null 2>$null
    $up = ($LASTEXITCODE -eq 0)
    $ErrorActionPreference = $prev
    return $up
}

# --------------------------------------------------------------- prerequisites

Step 'Checking Docker'
if (-not (Test-DockerUp)) {
    $desktop = @(
        "$env:ProgramFiles\Docker\Docker\Docker Desktop.exe",
        "${env:ProgramFiles(x86)}\Docker\Docker\Docker Desktop.exe"
    ) | Where-Object { Test-Path $_ } | Select-Object -First 1

    if (-not $desktop) { Die 'Docker is not running and Docker Desktop was not found. Start Docker and try again.' }

    Info 'Docker is not running — starting Docker Desktop (this takes up to a minute)'
    Start-Process $desktop | Out-Null

    $deadline = (Get-Date).AddSeconds(180)
    while ((Get-Date) -lt $deadline -and -not (Test-DockerUp)) { Start-Sleep -Seconds 5 }

    if (-not (Test-DockerUp)) { Die 'Docker Desktop did not become ready within 180 seconds.' }
}
Info 'Docker is running.'

if (-not (Test-Path 'api\.env')) {
    Info 'api\.env missing — copying api\.env.example'
    Copy-Item 'api\.env.example' 'api\.env'
}

# ------------------------------------------------------------------- teardown

if ($Fresh) {
    Step 'Tearing down existing stack and volumes'
    Invoke-Compose @('down', '-v', '--remove-orphans') 'docker compose down failed.'
}

if ($Rebuild) {
    Step 'Rebuilding the PHP image'
    Invoke-Compose @('build', '--pull', 'api') 'Image build failed.'
}

# ---------------------------------------------------------------- PHP deps/key

if (-not (Test-Path 'api\vendor\autoload.php')) {
    Step 'Installing PHP dependencies (first run — this takes a minute)'
    Invoke-Compose @('run', '--rm', '--no-deps', 'api', 'composer', 'install',
                     '--no-interaction', '--prefer-dist', '--no-progress') 'composer install failed.'
}

if (-not (Select-String -Path 'api\.env' -Pattern '^APP_KEY=.+' -Quiet)) {
    Step 'Generating application key'
    Invoke-Compose @('run', '--rm', '--no-deps', 'api', 'php', 'artisan', 'key:generate', '--force') 'key:generate failed.'
}

# ------------------------------------------------------------------- the stack

Step 'Starting the stack'
Invoke-Compose @('up', '-d', '--remove-orphans') 'docker compose up failed.'
Info 'postgres · redis · minio · api · worker · scheduler · web'

Step 'Waiting for the API'
[void](Wait-Url -Url "$ApiUrl/circles" -TimeoutSeconds 180 -Label 'API')
Info "$ApiUrl is answering."

# -------------------------------------------------------------- schema + data

Step 'Applying migrations and seeding the agent blueprint'
Invoke-Compose @('exec', '-T', 'api', 'php', 'artisan', 'migrate', '--force', '--seed') 'Migration failed.'

# Organisation setup is an administrative act, not a self-service endpoint, so
# a usable sign-in has to be provisioned here. These are the same accounts
# demo.py uses, so the two paths agree.
Step 'Provisioning the demo organisation and accounts'
Invoke-Compose @('exec', '-T', 'api', 'php', 'artisan', 'circle:provision-org', 'JWA Mats',
    "--user=gm@jwamats.test:Dana Okafor (GM):$DemoPass",
    "--user=commercial@jwamats.test:Priya Raman (Commercial):$DemoPass",
    "--user=technical@jwamats.test:Tom Alvarez (Technical):$DemoPass",
    "--user=logistics@jwamats.test:Kim Novak (Logistics):$DemoPass",
    "--user=external@northernrail.test:Sam Bright (Client):$DemoPass",
    '--external=external@northernrail.test') 'Provisioning failed.'

# ---------------------------------------------------------------- the frontend

Step 'Waiting for the web server (npm install runs inside the container on first boot)'
[void](Wait-Url -Url $WebUrl -TimeoutSeconds 420 -Label 'Web')
Info "$WebUrl is answering."

# ------------------------------------------------------------------- the demo

if ($Demo) {
    Step 'Running the First Demo Script'
    $python = (Get-Command python -ErrorAction SilentlyContinue)
    if (-not $python) { $python = (Get-Command python3 -ErrorAction SilentlyContinue) }
    if (-not $python) {
        Info 'No python on PATH — skipping. Run "python demo.py" yourself once Python is installed.'
    } else {
        $env:KEEP_OPEN = '1'
        & $python.Source demo.py
        if ($LASTEXITCODE -ne 0) { Write-Host '   demo.py reported failures (stack is still up).' -ForegroundColor Yellow }
    }
}

# ---------------------------------------------------------------------- ready

Write-Host ''
Write-Host '  Circle is up.' -ForegroundColor Green
Write-Host ''
Write-Host "  Web            $WebUrl"
Write-Host "  API            $ApiUrl"
Write-Host "  MinIO console  $MinioUrl   (circle / circlecircle)"
Write-Host ''
Write-Host "  Sign in as     $DemoUser"
Write-Host "  Password       $DemoPass"
Write-Host ''
Write-Host '  Logs           docker compose logs -f'
Write-Host '  Stop           docker compose down'
Write-Host ''

if (-not $NoBrowser) { Start-Process $WebUrl }
