<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/StudentImportParser.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function assertStudentImport(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = tempnam(sys_get_temp_dir(), 'student-import-');
if ($path === false) {
    throw new RuntimeException('No se pudo crear el archivo temporal de pruebas.');
}

try {
    $workbook = StudentImportParser::createTemplate();
    assertStudentImport(
        $workbook->getSheetNames() === ['Alumnado', 'Instrucciones'],
        'La plantilla debe incluir una hoja de datos y otra de instrucciones.'
    );
    assertStudentImport(
        $workbook->getSheet(0)->rangeToArray('A1:F1')[0] === [
            'Email', 'DNI/NIE', 'Nombre', 'Apellidos', 'Ciclo formativo', 'Curso',
        ],
        'La plantilla debe exponer todas las columnas requeridas.'
    );
    $registeredStudents = [[
        'correo' => '=HYPERLINK("https://example.invalid")',
        'dni' => '12345678Z',
        'tipo_documento' => 'NIF_NIE_DNI',
        'nombre' => 'Ana',
        'apellidos' => 'Prueba',
        'nombre_especialidad' => 'Ciclo de prueba',
        'curso' => 1,
    ]];
    $sampleRows = [];
    $checkLetters = 'TRWAGMYFPDXBNJZSQVHLCKE';
    for ($index = 1; $index <= 15; $index++) {
        $number = 12345678 + $index;
        $sampleRows[] = [
            'email' => sprintf('prueba.importacion.alumno%02d@example.invalid', $index),
            'dni' => (string)$number . $checkLetters[$number % 23],
            'nombre' => 'Alumno',
            'apellidos' => 'Prueba',
            'ciclo' => 'Ciclo de prueba',
            'curso' => '1',
        ];
    }
    assertStudentImport(
        count(array_unique(array_column($sampleRows, 'dni'))) === 15,
        'Los registros ficticios deben tener documentos únicos.'
    );
    $verification = StudentImportParser::createVerificationWorkbook($registeredStudents, $sampleRows);
    assertStudentImport(
        $verification->getSheetNames() === ['Datos de prueba', 'Alumnado registrado', 'Instrucciones'],
        'El Excel de verificación debe separar el listado real, las filas ficticias y las instrucciones.'
    );
    assertStudentImport(
        $verification->getSheet(0)->getHighestDataRow() === 16
            && $verification->getSheet(0)->rangeToArray('A1:F1')[0] === [
                'Email', 'DNI/NIE', 'Nombre', 'Apellidos', 'Ciclo formativo', 'Curso',
            ],
        'La primera hoja debe ser importable y tener al menos 15 filas con las columnas requeridas.'
    );
    assertStudentImport(
        $verification->getSheet(1)->getHighestDataRow() === 2
            && $verification->getSheet(1)->getCell('A2')->getDataType() === DataType::TYPE_STRING
            && $verification->getSheet(1)->getCell('A2')->getValue() === '=HYPERLINK("https://example.invalid")',
        'Los datos registrados deben exportarse como texto para evitar fórmulas ejecutables.'
    );
    (new Xlsx($verification))->save($path);
    $parsedVerification = StudentImportParser::parse($path);
    assertStudentImport(
        count($parsedVerification) === 15
            && $parsedVerification[0]['email'] === 'prueba.importacion.alumno01@example.invalid',
        'Al subir el Excel completo, el importador debe leer únicamente los 15 registros de prueba de la primera hoja.'
    );
    $verification->disconnectWorksheets();
    try {
        StudentImportParser::createVerificationWorkbook($registeredStudents, array_slice($sampleRows, 0, 14));
        assertStudentImport(false, 'El Excel de verificación no debe permitir menos de 15 filas ficticias.');
    } catch (InvalidArgumentException) {
    }
    (new Xlsx($workbook))->save($path);
    $workbook->disconnectWorksheets();

    $book = new Spreadsheet();
    $sheet = $book->getActiveSheet();
    $sheet->fromArray([
        ['Apellidos', 'Curso', 'Ciclo Formativo', 'Email', 'DNI/NIE', 'Nombre'],
        ['López', 1, 'Desarrollo de Aplicaciones Multiplataforma (DAM)', 'alumno1@example.test', '12345678Z', 'Ana'],
        ['', '', '', '', '', ''],
        ['Datos incompletos', '', '', '', '', ''],
    ], null, 'A1');
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();
    $rows = StudentImportParser::parse($path);
    assertStudentImport(count($rows) === 2, 'Debe ignorar filas vacías y conservar las incompletas para informar errores.');
    assertStudentImport(
        $rows[0]['linea'] === 2
            && $rows[0]['email'] === 'alumno1@example.test'
            && $rows[0]['dni'] === '12345678Z'
            && $rows[0]['ciclo'] === 'Desarrollo de Aplicaciones Multiplataforma (DAM)',
        'El mapeo de cabeceras debe admitir cualquier orden de columnas.'
    );
    assertStudentImport(
        $rows[1]['linea'] === 4 && $rows[1]['apellidos'] === 'Datos incompletos',
        'El parser debe conservar el número real de fila del Excel.'
    );

    $largeBook = new Spreadsheet();
    $largeSheet = $largeBook->getActiveSheet();
    $largeRows = [['Email', 'DNI/NIE', 'Nombre', 'Apellidos', 'Ciclo formativo', 'Curso']];
    for ($index = 1; $index <= 1001; $index++) {
        $largeRows[] = [
            "alumno$index@example.test",
            '12345678Z',
            'Alumno',
            "Prueba $index",
            'DAM',
            '1',
        ];
    }
    $largeSheet->fromArray($largeRows, null, 'A1');
    (new Xlsx($largeBook))->save($path);
    $largeBook->disconnectWorksheets();
    $largeParsed = StudentImportParser::parse($path);
    assertStudentImport(count($largeParsed) === 1001, 'El parser debe admitir importaciones que superan 1.000 filas.');
    assertStudentImport($largeParsed[1000]['linea'] === 1002, 'El parser debe conservar la posición de la última fila.');

    $badBook = new Spreadsheet();
    $badBook->getActiveSheet()->fromArray([['Correo', 'Nombre'], ['alumno@example.test', 'Ana']], null, 'A1');
    (new Xlsx($badBook))->save($path);
    $badBook->disconnectWorksheets();
    try {
        StudentImportParser::parse($path);
        assertStudentImport(false, 'Debe rechazarse una cabecera sin todas las columnas obligatorias.');
    } catch (StudentImportException) {
    }
} finally {
    @unlink($path);
}

echo "student_import: OK\n";
