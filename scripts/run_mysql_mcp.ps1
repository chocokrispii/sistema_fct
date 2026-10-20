$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$localSecretPath = Join-Path $repoRoot '.env.mcp.local'

if (-not (Test-Path -LiteralPath $localSecretPath)) {
    throw "Falta .env.mcp.local. Ejecuta .\scripts\configurar_mcp_db.ps1 desde la raíz del proyecto."
}

$secretMatch = [regex]::Match(
    (Get-Content -LiteralPath $localSecretPath -Raw),
    '(?m)^MCP_DB_PASS=([A-Fa-f0-9]{64})\s*$'
)
if (-not $secretMatch.Success) {
    throw 'MCP_DB_PASS no existe o no tiene el formato esperado en .env.mcp.local.'
}

$dockerEnvPath = Join-Path $repoRoot '.env'
$database = 'sistema_fct'
if (Test-Path -LiteralPath $dockerEnvPath) {
    $databaseMatch = [regex]::Match(
        (Get-Content -LiteralPath $dockerEnvPath -Raw),
        '(?m)^FCT_DB_NAME=([A-Za-z0-9_]+)\s*$'
    )
    if ($databaseMatch.Success) {
        $database = $databaseMatch.Groups[1].Value
    }
}

$env:MYSQL_PASS = $secretMatch.Groups[1].Value
$env:MYSQL_DATABASE = $database
try {
    & npx.cmd --yes '@benborla29/mcp-server-mysql@2.0.9'
    exit $LASTEXITCODE
}
finally {
    Remove-Item Env:\MYSQL_PASS -ErrorAction SilentlyContinue
    Remove-Item Env:\MYSQL_DATABASE -ErrorAction SilentlyContinue
}
