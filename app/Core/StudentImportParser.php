<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

final class StudentImportException extends RuntimeException
{
}

final class StudentImportParser
{
    public const MAX_ROWS = 5000;
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;
    public const COLUMNS = ['email', 'dni', 'nombre', 'apellidos', 'ciclo', 'curso'];

    private const HEADERS = [
        'email' => ['email', 'correo', 'correoelectronico', 'mail'],
        'dni' => ['dni', 'nie', 'dninie', 'numerodocumento', 'documentodeidentidad'],
        'nombre' => ['nombre', 'name'],
        'apellidos' => ['apellidos', 'apellido', 'surname', 'surnames'],
        'ciclo' => ['ciclo', 'cicloformativo', 'especialidad', 'especialidadformativa'],
        'curso' => ['curso', 'year', 'course'],
    ];

    /**
     * @return list<array{linea:int,email:string,dni:string,nombre:string,apellidos:string,ciclo:string,curso:string}>
     */
    public static function parse(string $path): array
    {
        try {
            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);
            $sheets = $reader->listWorksheetInfo($path);
            $firstSheet = $sheets[0] ?? null;
            if (!is_array($firstSheet)) {
                throw new StudentImportException('El archivo no contiene una hoja de alumnado.');
            }
            if ((int)($firstSheet['totalRows'] ?? 0) > self::MAX_ROWS + 1) {
                throw new StudentImportException('La plantilla admite un máximo de ' . self::MAX_ROWS . ' alumnos por archivo.');
            }
            if ((int)($firstSheet['totalColumns'] ?? 0) > 32) {
                throw new StudentImportException('El archivo contiene demasiadas columnas.');
            }

            $reader->setLoadSheetsOnly([(string)$firstSheet['worksheetName']]);
            $sheet = $reader->load($path)->getSheet(0);
            $lastRow = min($sheet->getHighestDataRow(), self::MAX_ROWS + 1);
            $lastColumn = min(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
                32
            );
            $headerMap = null;
            $rows = [];

            for ($rowIndex = 1; $rowIndex <= $lastRow; $rowIndex++) {
                $cells = [];
                for ($columnIndex = 1; $columnIndex <= $lastColumn; $columnIndex++) {
                    $cell = $sheet->getCell([$columnIndex, $rowIndex]);
                    $value = $cell->getDataType() === DataType::TYPE_FORMULA ? '' : $cell->getValue();
                    $cells[] = is_scalar($value) ? trim((string)$value) : '';
                }
                if (implode('', $cells) === '') {
                    continue;
                }

                if ($headerMap === null) {
                    $headerMap = self::detectHeader($cells);
                    if ($headerMap === null) {
                        throw new StudentImportException(
                            'La primera fila debe incluir las columnas Email, DNI/NIE, Nombre, Apellidos, Ciclo formativo y Curso.'
                        );
                    }
                    continue;
                }

                $row = ['linea' => $rowIndex];
                foreach (self::COLUMNS as $column) {
                    $row[$column] = $cells[$headerMap[$column]] ?? '';
                }
                $rows[] = $row;
            }

            if ($headerMap === null) {
                throw new StudentImportException('El archivo está vacío o no contiene una cabecera válida.');
            }
            if ($rows === []) {
                throw new StudentImportException('No se encontraron filas de alumnado en el archivo.');
            }
            if (count($rows) > self::MAX_ROWS) {
                throw new StudentImportException('La plantilla admite un máximo de ' . self::MAX_ROWS . ' alumnos por archivo.');
            }

            return $rows;
        } catch (StudentImportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            error_log('Importación masiva de alumnado: Excel ilegible: ' . $exception->getMessage());
            throw new StudentImportException('No se pudo leer el archivo Excel. Descarga la plantilla y vuelve a intentarlo.');
        }
    }

    public static function createTemplate(): Spreadsheet
    {
        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('Alumnado');
        $headers = ['Email', 'DNI/NIE', 'Nombre', 'Apellidos', 'Ciclo formativo', 'Curso'];
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE8EEF8');
        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(22);
        $sheet->getColumnDimension('D')->setWidth(28);
        $sheet->getColumnDimension('E')->setWidth(52);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:F1');

        $instructions = $workbook->createSheet();
        $instructions->setTitle('Instrucciones');
        $lines = [
            ['Importación masiva de alumnado'],
            ['Completa una fila por alumno en la hoja Alumnado. No cambies los encabezados.'],
            ['Email', 'Correo único al que se enviará el enlace de activación.'],
            ['DNI/NIE', 'Documento válido. Escríbelo como texto para conservar ceros iniciales.'],
            ['Nombre / Apellidos', 'Nombre y apellidos del alumno.'],
            ['Ciclo formativo', 'Escribe el nombre exacto de un ciclo activo que aparece en el sistema.'],
            ['Curso', 'Usa 1 o 2.'],
            ['Límite', 'Máximo ' . self::MAX_ROWS . ' filas de alumnos por archivo.'],
            ['Privacidad', 'El archivo se analiza antes de procesarlo y no se conserva después de la importación.'],
        ];
        foreach ($lines as $rowIndex => $line) {
            foreach ($line as $columnIndex => $value) {
                $instructions->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], $value, DataType::TYPE_STRING);
            }
        }
        $instructions->getColumnDimension('A')->setWidth(28);
        $instructions->getColumnDimension('B')->setWidth(100);
        $instructions->getStyle('A1:B1')->getFont()->setBold(true);

        return $workbook;
    }

    /**
     * @param list<array{correo:string,dni:string,tipo_documento:?string,nombre:string,apellidos:string,nombre_especialidad:?string,curso:string|int}> $registeredStudents
     * @param list<array{email:string,dni:string,nombre:string,apellidos:string,ciclo:string,curso:string}> $sampleRows
     */
    public static function createVerificationWorkbook(array $registeredStudents, array $sampleRows): Spreadsheet
    {
        if (count($sampleRows) < 15) {
            throw new InvalidArgumentException('El Excel de verificación debe incluir al menos 15 registros ficticios.');
        }

        $workbook = self::createTemplate();
        $sampleSheet = $workbook->getSheet(0);
        $sampleSheet->setTitle('Datos de prueba');
        $headers = ['Email', 'DNI/NIE', 'Nombre', 'Apellidos', 'Ciclo formativo', 'Curso'];
        foreach ($headers as $index => $header) {
            $sampleSheet->setCellValueExplicit([$index + 1, 1], $header, DataType::TYPE_STRING);
        }
        $sampleSheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sampleSheet->getStyle('A1:F1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE8EEF8');
        foreach (['A' => 32, 'B' => 18, 'C' => 22, 'D' => 28, 'E' => 52, 'F' => 12] as $column => $width) {
            $sampleSheet->getColumnDimension($column)->setWidth($width);
        }
        $sampleSheet->freezePane('A2');
        $sampleSheet->setAutoFilter('A1:F1');

        foreach ($sampleRows as $index => $row) {
            foreach (self::COLUMNS as $columnIndex => $column) {
                $sampleSheet->setCellValueExplicit(
                    [$columnIndex + 1, $index + 2],
                    $row[$column],
                    DataType::TYPE_STRING
                );
            }
        }

        $registeredSheet = $workbook->createSheet(1);
        $registeredSheet->setTitle('Alumnado registrado');
        $registeredSheet->setCellValueExplicit('G1', 'Tipo de documento', DataType::TYPE_STRING);
        $registeredSheet->getStyle('A1:G1')->getFont()->setBold(true);
        $registeredSheet->getStyle('A1:G1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE8EEF8');
        $registeredSheet->getColumnDimension('G')->setWidth(22);
        $registeredSheet->setAutoFilter('A1:G1');

        foreach ($registeredStudents as $index => $student) {
            $values = [
                $student['correo'],
                $student['dni'],
                $student['nombre'],
                $student['apellidos'],
                $student['nombre_especialidad'],
                (string)$student['curso'],
                $student['tipo_documento'],
            ];
            foreach ($values as $columnIndex => $value) {
                $registeredSheet->setCellValueExplicit(
                    [$columnIndex + 1, $index + 2],
                    is_scalar($value) ? (string)$value : '',
                    DataType::TYPE_STRING
                );
            }
        }

        $instructions = $workbook->getSheetByName('Instrucciones');
        if ($instructions === false) {
            throw new RuntimeException('No se pudo preparar la hoja de instrucciones.');
        }
        $instructions->setCellValueExplicit(
            'A2',
            'Datos de prueba es la primera hoja y se puede subir directamente para probar las altas.',
            DataType::TYPE_STRING
        );
        $instructions->setCellValueExplicit('A10', 'Alumnado registrado', DataType::TYPE_STRING);
        $instructions->setCellValueExplicit(
            'B10',
            'Muestra los datos actuales del sistema. Es solo de consulta y no debe importarse como nuevas altas.',
            DataType::TYPE_STRING
        );
        $instructions->setCellValueExplicit('A11', 'Uso', DataType::TYPE_STRING);
        $instructions->setCellValueExplicit(
            'B11',
            'Sube el archivo tal cual: la importación procesa la primera hoja y omite las demás.',
            DataType::TYPE_STRING
        );
        $instructions->setCellValueExplicit('A12', 'Activación', DataType::TYPE_STRING);
        $instructions->setCellValueExplicit(
            'B12',
            'Las altas de prueba usan correos .invalid; en desarrollo los mensajes se capturan en Mailpit y permiten completar una contraseña local.',
            DataType::TYPE_STRING
        );

        return $workbook;
    }

    /**
     * @param list<string> $values
     * @return array<string,int>|null
     */
    private static function detectHeader(array $values): ?array
    {
        $map = [];
        foreach ($values as $position => $value) {
            $normalized = self::normalizeHeader($value);
            foreach (self::HEADERS as $field => $aliases) {
                if (!isset($map[$field]) && in_array($normalized, $aliases, true)) {
                    $map[$field] = $position;
                }
            }
        }

        return count($map) === count(self::COLUMNS) ? $map : null;
    }

    private static function normalizeHeader(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
        return preg_replace('/[^a-z0-9]/', '', $value) ?? '';
    }
}
