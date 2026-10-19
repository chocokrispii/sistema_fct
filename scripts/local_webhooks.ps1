param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('All', 'Server', 'App', 'Interface', 'Dispatch', 'Stop')]
    [string]$Mode
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$configPath = Join-Path $projectRoot '.env.local'
$php = 'C:\xampp\php\php.exe'
$statePath = Join-Path $env:TEMP 'fct-local-servers.json'
$stopRequestPath = Join-Path $env:TEMP 'fct-local-servers.stop'

if ($Mode -eq 'Stop') {
    if (-not (Test-Path -LiteralPath $statePath -PathType Leaf)) {
        Write-Output 'No hay servidores locales iniciados por este lanzador.'
        exit 0
    }
    Set-Content -LiteralPath $stopRequestPath -Value 'stop' -Encoding ascii
    for ($attempt = 0; $attempt -lt 15; $attempt++) {
        if (-not (Test-Path -LiteralPath $statePath -PathType Leaf)) {
            Remove-Item -LiteralPath $stopRequestPath -Force -ErrorAction SilentlyContinue
            Write-Output 'Servidores locales detenidos.'
            exit 0
        }
        Start-Sleep -Seconds 1
    }
    throw 'El lanzador no confirmó la detención. Comprueba que su ventana de PowerShell siga activa.'
}

if (-not (Test-Path -LiteralPath $configPath -PathType Leaf)) {
    throw "No existe .env.local. Crea ese archivo local con la configuración webhook documentada en docs\webhooks.md."
}
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    throw "No se encontró PHP de XAMPP en $php."
}

$allowedVariables = @(
    'FCT_DB_HOST',
    'FCT_DB_NAME',
    'FCT_DB_USER',
    'FCT_DB_PASS',
    'FCT_WEBHOOK_INBOUND_SECRET',
    'FCT_WEBHOOK_OUTBOUND_SECRET',
    'FCT_WEBHOOK_OUTBOUND_URL',
    'FCT_STORAGE_PATH',
    'FCT_CLAMD_HOST',
    'FCT_CLAMD_PORT',
    'FCT_CLAMSCAN',
    'FCT_CLAMAV_DATABASE'
)
$loadedVariables = @{}
foreach ($line in Get-Content -LiteralPath $configPath) {
    if ([string]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith('#')) {
        continue
    }
    if ($line -notmatch '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
        throw 'Una línea de .env.local no tiene el formato NOMBRE=valor.'
    }
    $name = $Matches[1]
    if ($name -notin $allowedVariables) {
        throw "La variable $name no está permitida en la configuración local de webhooks."
    }
    if ($loadedVariables.ContainsKey($name)) {
        throw "La variable $name está duplicada en .env.local."
    }
    $loadedVariables[$name] = $Matches[2]
}

foreach ($name in @('FCT_WEBHOOK_INBOUND_SECRET', 'FCT_WEBHOOK_OUTBOUND_SECRET')) {
    if (-not $loadedVariables.ContainsKey($name) -or $loadedVariables[$name].Length -lt 32) {
        throw "$name debe estar configurado en .env.local con al menos 32 caracteres."
    }
}
if ($loadedVariables['FCT_WEBHOOK_INBOUND_SECRET'] -cne $loadedVariables['FCT_WEBHOOK_OUTBOUND_SECRET']) {
    throw 'Para la prueba local contra el propio endpoint, ambos secretos deben coincidir.'
}
if (-not $loadedVariables.ContainsKey('FCT_WEBHOOK_OUTBOUND_URL') -or
    $loadedVariables['FCT_WEBHOOK_OUTBOUND_URL'] -cne 'http://127.0.0.1:8001/webhook.php') {
    throw 'FCT_WEBHOOK_OUTBOUND_URL debe ser http://127.0.0.1:8001/webhook.php.'
}

foreach ($name in $loadedVariables.Keys) {
    [Environment]::SetEnvironmentVariable($name, $loadedVariables[$name], 'Process')
}
if (-not $loadedVariables.ContainsKey('FCT_STORAGE_PATH')) {
    $storagePath = Join-Path $env:LOCALAPPDATA 'FCT\storage'
    New-Item -ItemType Directory -Path $storagePath -Force | Out-Null
    $env:FCT_STORAGE_PATH = $storagePath
}
if (-not $loadedVariables.ContainsKey('FCT_CLAMD_HOST') -and -not $loadedVariables.ContainsKey('FCT_CLAMSCAN')) {
    $env:FCT_CLAMD_HOST = '127.0.0.1'
    $env:FCT_CLAMD_PORT = '3310'
}
if (-not $loadedVariables.ContainsKey('FCT_DB_HOST')) {
    $env:FCT_DB_HOST = '127.0.0.1'
}
if (-not $loadedVariables.ContainsKey('FCT_DB_NAME')) {
    $env:FCT_DB_NAME = 'sistema_fct'
}

Push-Location $projectRoot
try {
    if ($Mode -eq 'Server') {
        & $php -S 127.0.0.1:8001 -t public
    } elseif ($Mode -eq 'App') {
        & $php -S 127.0.0.1:8002 -t public
    } elseif ($Mode -eq 'Interface') {
        & $php -S 127.0.0.1:8003 -t public
    } elseif ($Mode -eq 'Dispatch') {
        $env:FCT_WEBHOOK_DISPATCH_ALLOW = '1'
        & $php scripts\dispatch_webhooks.php
        if ($LASTEXITCODE -ne 0) {
            throw "El worker de webhooks terminó con código $LASTEXITCODE."
        }
    } else {
        $ports = @(
            @{ port = 8001; name = 'receptor' },
            @{ port = 8002; name = 'aplicacion' },
            @{ port = 8003; name = 'interfaz' }
        )
        foreach ($server in $ports) {
            $listener = Get-NetTCPConnection -State Listen -LocalPort $server.port -ErrorAction SilentlyContinue
            if ($null -ne $listener) {
                throw ("El puerto {0} ya está ocupado. Cierra el proceso que lo usa o ejecuta -Mode Stop si pertenece a este lanzador." -f $server.port)
            }
        }

        $logDirectory = Join-Path $env:TEMP 'fct-local-servers'
        New-Item -ItemType Directory -Path $logDirectory -Force | Out-Null
        Remove-Item -LiteralPath $stopRequestPath -Force -ErrorAction SilentlyContinue
        $started = @()
        try {
            foreach ($server in $ports) {
                $stdout = Join-Path $logDirectory ("{0}.out.log" -f $server.name)
                $stderr = Join-Path $logDirectory ("{0}.err.log" -f $server.name)
                $job = Start-Job -Name ("FCT-local-{0}" -f $server.port) `
                    -ArgumentList $php, $server.port, $projectRoot, $stdout, $stderr `
                    -ScriptBlock {
                        param($phpPath, $port, $root, $stdoutPath, $stderrPath)
                        Set-Location -LiteralPath $root
                        & $phpPath -S ("127.0.0.1:{0}" -f $port) -t public public\dev-router.php 1>> $stdoutPath 2>> $stderrPath
                    }
                $started += [pscustomobject]@{
                    jobId = $job.Id
                    port = $server.port
                }
            }
            $started | ConvertTo-Json | Set-Content -LiteralPath $statePath -Encoding ascii

            foreach ($server in $ports) {
                $ready = $false
                for ($attempt = 0; $attempt -lt 30; $attempt++) {
                    $client = New-Object System.Net.Sockets.TcpClient
                    try {
                        $connection = $client.BeginConnect('127.0.0.1', $server.port, $null, $null)
                        if ($connection.AsyncWaitHandle.WaitOne(200) -and $client.Connected) {
                            $client.EndConnect($connection)
                            $ready = $true
                            break
                        }
                    } finally {
                        $client.Close()
                    }
                    Start-Sleep -Milliseconds 100
                }
                if (-not $ready) {
                    throw ("No respondió el servidor del puerto {0}. Revisa sus logs en {1}." -f $server.port, $logDirectory)
                }
            }

            try {
                $appPage = Invoke-WebRequest -Uri 'http://127.0.0.1:8002/index.php' -UseBasicParsing -TimeoutSec 10
                if ($appPage.StatusCode -ne 200 -or $appPage.Content -notmatch 'Sistema FCT') {
                    throw 'La aplicación de 8002 no devolvió su página principal.'
                }
                $testPage = Invoke-WebRequest -Uri 'http://127.0.0.1:8003/webhook_test.php' -UseBasicParsing -TimeoutSec 10
                if ($testPage.StatusCode -ne 200 -or $testPage.Content -notmatch 'Probar webhooks') {
                    throw 'La interfaz de pruebas de 8003 no pudo cargarse.'
                }
                $receiverStatus = & curl.exe --silent --show-error --output NUL --write-out '%{http_code}' http://127.0.0.1:8001/webhook.php
                if ($LASTEXITCODE -ne 0 -or $receiverStatus -ne '405') {
                    throw 'El receptor de 8001 no respondió con el estado 405 esperado para una petición GET.'
                }
            } catch {
                throw ("Falló la comprobación local. Revisa los logs en {0}. Detalle: {1}" -f $logDirectory, $_.Exception.Message)
            }

            Write-Output 'Servidores locales listos:'
            Write-Output '  Aplicación:         http://127.0.0.1:8002/index.php'
            Write-Output '  Prueba de webhooks: http://127.0.0.1:8003/webhook_test.php'
            Write-Output '  Receptor webhook:   http://127.0.0.1:8001/webhook.php'
            Write-Output ("Logs locales:        {0}" -f $logDirectory)
            Write-Output 'Mantén abierta esta ventana. Pulsa Ctrl+C o usa -Mode Stop para detener los servidores.'
            while ($true) {
                if (Test-Path -LiteralPath $stopRequestPath -PathType Leaf) {
                    break
                }
                foreach ($server in $started) {
                    $serverJob = Get-Job -Id $server.jobId -ErrorAction SilentlyContinue
                    if ($null -eq $serverJob -or $serverJob.State -ne 'Running') {
                        throw ("El servidor del puerto {0} se detuvo inesperadamente." -f $server.port)
                    }
                }
                Start-Sleep -Seconds 2
            }
        } catch {
            foreach ($server in $started) {
                Stop-Job -Id $server.jobId -ErrorAction SilentlyContinue
            }
            Remove-Item -LiteralPath $statePath -Force -ErrorAction SilentlyContinue
            throw
        } finally {
            foreach ($server in $started) {
                Stop-Job -Id $server.jobId -ErrorAction SilentlyContinue
                Remove-Job -Id $server.jobId -Force -ErrorAction SilentlyContinue
            }
            Remove-Item -LiteralPath $statePath -Force -ErrorAction SilentlyContinue
            Remove-Item -LiteralPath $stopRequestPath -Force -ErrorAction SilentlyContinue
        }
    }
} finally {
    Remove-Item Env:\FCT_WEBHOOK_DISPATCH_ALLOW -ErrorAction SilentlyContinue
    Pop-Location
}
