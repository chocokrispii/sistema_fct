$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$localSecretPath = Join-Path $repoRoot '.env.mcp.local'
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

if (-not (Test-Path -LiteralPath $localSecretPath)) {
    $randomBytes = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($randomBytes)
    }
    finally {
        $generator.Dispose()
    }
    $password = [BitConverter]::ToString($randomBytes).Replace('-', '').ToLowerInvariant()
    [System.IO.File]::WriteAllText(
        $localSecretPath,
        "MCP_DB_PASS=$password`r`n",
        (New-Object System.Text.UTF8Encoding($false))
    )
}

$secretMatch = [regex]::Match(
    (Get-Content -LiteralPath $localSecretPath -Raw),
    '(?m)^MCP_DB_PASS=([A-Fa-f0-9]{64})\s*$'
)
if (-not $secretMatch.Success) {
    throw "No se encontró un MCP_DB_PASS hexadecimal de 64 caracteres en $localSecretPath."
}

$password = $secretMatch.Groups[1].Value
$sql = @"
CREATE USER IF NOT EXISTS 'fct_mcp_readonly'@'%' IDENTIFIED BY '$password';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'fct_mcp_readonly'@'%';
ALTER USER 'fct_mcp_readonly'@'%' IDENTIFIED BY '$password';
GRANT SELECT, SHOW VIEW ON ``$database``.* TO 'fct_mcp_readonly'@'%';
FLUSH PRIVILEGES;
"@

Push-Location $repoRoot
try {
    $sql | & docker compose exec -T db sh -c 'mariadb --protocol=socket --user=root --password="$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
    if ($LASTEXITCODE -ne 0) {
        throw "No se pudo configurar la cuenta MariaDB de solo lectura (código $LASTEXITCODE). Comprueba que el servicio db está iniciado."
    }
}
finally {
    Pop-Location
}

Write-Output "Cuenta MCP de solo lectura configurada para la base '$database'. El secreto permanece en .env.mcp.local."
