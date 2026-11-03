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

    $pdfText = "BT /F1 12 Tf 50 750 Td (ACME SA;A58818501;ana@acme.example;Ana Perez) Tj 0 -20 Td (Fila incompleta sin correo) Tj ET";
    $pdf = "%PDF-1.4\n"
        . "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n"
        . "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n"
        . "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >> endobj\n"
        . "4 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n"
        . "5 0 obj << /Length " . strlen($pdfText) . " >> stream\n" . $pdfText . "\nendstream endobj\n";
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($object = 1; $object <= 5; $object++) {
        $pdf .= sprintf("%010d 00000 n \n", strpos($pdf, "$object 0 obj"));
    }
    $pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n$xrefOffset\n%%EOF";
    file_put_contents($xlsx, $pdf);
    $pdfRows = CompanyImportParser::parse($xlsx, 'pdf');
    assertCompanyImport(count($pdfRows) === 2, 'El PDF debe conservar las filas invalidas para informar sus errores.');
    assertCompanyImport($pdfRows[0]['nif'] === 'A58818501' && $pdfRows[0]['email'] === 'ana@acme.example', 'El PDF debe extraer los datos de empresa.');
    assertCompanyImport($pdfRows[1]['nif'] === '' && $pdfRows[1]['linea'] === 2, 'Una fila PDF incompleta debe reportarse con su numero de linea.');

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
