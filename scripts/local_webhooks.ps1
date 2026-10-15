param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Server', 'Interface', 'Dispatch')]
    [string]$Mode
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$configPath = Join-Path $projectRoot '.env.local'
$php = 'C:\xampp\php\php.exe'

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
    'FCT_WEBHOOK_OUTBOUND_URL'
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
    } elseif ($Mode -eq 'Interface') {
        & $php -S 127.0.0.1:8003 -t public
    } else {
        $env:FCT_WEBHOOK_DISPATCH_ALLOW = '1'
        & $php scripts\dispatch_webhooks.php
        if ($LASTEXITCODE -ne 0) {
            throw "El worker de webhooks terminó con código $LASTEXITCODE."
        }
    }
} finally {
    Remove-Item Env:\FCT_WEBHOOK_DISPATCH_ALLOW -ErrorAction SilentlyContinue
    Pop-Location
}
