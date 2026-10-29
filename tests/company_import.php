<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/CompanyImportParser.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function assertCompanyImport(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$xlsx = tempnam(sys_get_temp_dir(), 'imp') ?: throw new RuntimeException('tmp');
try {
    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([
        ['Representante', 'Email', 'NIF', 'Razón Social'],
        ['Ana Pérez', 'ana@acme.example', 'A58818501', 'ACME S.A.'],
        [null, null, null, null],
        ['Luis Gil', 'luis@beta.example', 'B12345678', 'Beta S.L.'],
    ]);
    (new Xlsx($book))->save($xlsx);
    $rows = CompanyImportParser::parse($xlsx, 'xlsx');
    assertCompanyImport(count($rows) === 2, 'Debe leer 2 filas y saltar la vacía.');
    assertCompanyImport($rows[0]['razon_social'] === 'ACME S.A.' && $rows[0]['nif'] === 'A58818501', 'Mapeo por cabecera incorrecto.');
    assertCompanyImport($rows[1]['email'] === 'luis@beta.example' && $rows[1]['linea'] === 4, 'Segunda fila incorrecta.');

    file_put_contents($xlsx, 'no es un excel');
    try {
        CompanyImportParser::parse($xlsx, 'xlsx');
        assertCompanyImport(false, 'Un Excel corrupto debe rechazarse.');
    } catch (CompanyImportException) {
    }
    try {
        CompanyImportParser::parse($xlsx, 'exe');
        assertCompanyImport(false, 'Una extensión no admitida debe rechazarse.');
    } catch (CompanyImportException) {
    }
} finally {
    @unlink($xlsx);
}

echo "company_import: OK\n";
