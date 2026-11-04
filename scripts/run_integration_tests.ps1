$ErrorActionPreference = 'Stop'

$databaseService = 'integration-db'
$integrationDatabase = 'fct_test_integration'
$tests = @(
    'tests/run.php',
    'tests/company_import_db.php',
    'tests/convenios_unificado.php',
    'tests/offers_legacy_schedule.php',
    'tests/matching_flexibility.php',
    'tests/api_openapi.php',
    'tests/webhooks.php'
)
$databaseStarted = $true

try {
    docker compose --profile integration up -d --force-recreate --wait $databaseService
    if ($LASTEXITCODE -ne 0) {
        throw 'No se pudo inicializar la base desechable de integración.'
    }

    foreach ($test in $tests) {
        Write-Host "Ejecutando $test contra $integrationDatabase"
        docker compose --profile integration run --rm --no-deps `
            -e APP_ENV=development `
            -e FCT_DB_HOST=$databaseService `
            -e FCT_DB_NAME=$integrationDatabase `
            -e FCT_DB_USER=fct `
            -e FCT_DB_PASS=fct_test_local `
            -e FCT_TEST_DB_NAME=$integrationDatabase `
            app php $test
        if ($LASTEXITCODE -ne 0) {
            throw "Falló la prueba de integración: $test"
        }
    }
}
finally {
    if ($databaseStarted) {
        docker compose --profile integration stop $databaseService | Out-Null
        docker compose --profile integration rm --force -v $databaseService | Out-Null
    }
}

Write-Host 'Suite de integración completada; la base temporal fue eliminada.'
