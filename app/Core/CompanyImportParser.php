<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use Smalot\PdfParser\Parser as PdfParser;

final class CompanyImportException extends RuntimeException
{
}

/**
 * Extrae filas {razon_social, nif, email, representante} de un Excel o PDF ya analizado por ClamAV.
 */
final class CompanyImportParser
{
    public const MAX_ROWS = 100;

    private const HEADER_ALIASES = [
        'razon_social' => ['razonsocial', 'razon', 'empresa', 'nombre', 'nombreempresa', 'nombrecomercial'],
        'nif' => ['nif', 'cif', 'nifcif', 'identificadorfiscal'],
        'email' => ['email', 'correo', 'correoelectronico', 'mail'],
        'representante' => ['representante', 'contacto', 'personacontacto', 'representantelegal'],
    ];

    /** @return list<array{linea:int, razon_social:string, nif:string, email:string, representante:string}> */
    public static function parse(string $path, string $extension): array
    {
        $rows = match (strtolower($extension)) {
            'xlsx' => self::parseXlsx($path),
            'pdf' => self::parsePdf($path),
            default => throw new CompanyImportException('Formato no admitido. Sube un archivo .xlsx o .pdf.'),
        };
        if ($rows === []) {
            throw new CompanyImportException('No se encontraron empresas en el archivo.');
        }
        if (count($rows) > self::MAX_ROWS) {
            throw new CompanyImportException('El archivo supera el máximo de ' . self::MAX_ROWS . ' empresas por importación.');
        }
        return $rows;
    }

    /** @return list<array{linea:int, razon_social:string, nif:string, email:string, representante:string}> */
    private static function parseXlsx(string $path): array
    {
        try {
            $reader = new XlsxReader();
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $sheet = $reader->load($path)->getSheet(0);
            $matrix = $sheet->toArray(null, false, false, false);
        } catch (Throwable $e) {
            error_log('Importación de empresas: Excel ilegible: ' . $e->getMessage());
            throw new CompanyImportException('No se pudo leer el archivo Excel.');
        }

        $map = ['razon_social' => 0, 'nif' => 1, 'email' => 2, 'representante' => 3];
        $first = true;
        $rows = [];
        foreach ($matrix as $index => $cells) {
            $values = array_map(static fn($cell): string => is_scalar($cell) ? trim((string)$cell) : '', $cells);
            if (implode('', $values) === '') {
                continue;
            }
            if ($first) {
                $first = false;
                $headerMap = self::detectHeader($values);
                if ($headerMap !== null) {
                    $map = $headerMap;
                    continue;
                }
            }
            $rows[] = [
                'linea' => $index + 1,
                'razon_social' => $values[$map['razon_social']] ?? '',
                'nif' => $values[$map['nif']] ?? '',
                'email' => $values[$map['email']] ?? '',
                'representante' => $values[$map['representante']] ?? '',
            ];
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        return $rows;
    }

    /** @return list<array{linea:int, razon_social:string, nif:string, email:string, representante:string}> */
    private static function parsePdf(string $path): array
    {
        try {
            $text = (new PdfParser())->parseFile($path)->getText();
        } catch (Throwable $e) {
            error_log('Importación de empresas: PDF ilegible: ' . $e->getMessage());
            throw new CompanyImportException('No se pudo extraer el texto del PDF.');
        }

        $rows = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $index => $line) {
            $line = trim($line);
            if ($line === '' || self::detectHeader(self::splitLine($line)) !== null) {
                continue;
            }
            $parts = self::splitLine($line);
            if (count($parts) < 4 && preg_match(
                '/^(?<razon>.+?)\s+(?<nif>[A-Za-z]\d{7}[A-Za-z0-9])\s+(?<email>\S+@\S+)\s+(?<rep>.+)$/u',
                $line,
                $m
            ) === 1) {
                $parts = [trim($m['razon']), $m['nif'], $m['email'], trim($m['rep'])];
            }
            if (count($parts) < 4) {
                $rows[] = ['linea' => $index + 1, 'razon_social' => $line, 'nif' => '', 'email' => '', 'representante' => ''];
                continue;
            }
            $rows[] = [
                'linea' => $index + 1,
                'razon_social' => $parts[0],
                'nif' => $parts[1],
                'email' => $parts[2],
                'representante' => $parts[3],
            ];
            if (count($rows) > self::MAX_ROWS) {
                break;
            }
        }
        return $rows;
    }

    /** @return list<string> */
    private static function splitLine(string $line): array
    {
        $parts = preg_split('/\s*[;|\t]\s*/u', $line) ?: [];
        return array_values(array_map('trim', $parts));
    }

    /** @param list<string> $values @return array<string,int>|null */
    private static function detectHeader(array $values): ?array
    {
        $map = [];
        foreach ($values as $position => $value) {
            $normalized = preg_replace('/[^a-z]/', '', strtolower(self::stripAccents($value))) ?? '';
            foreach (self::HEADER_ALIASES as $field => $aliases) {
                if (!isset($map[$field]) && in_array($normalized, $aliases, true)) {
                    $map[$field] = $position;
                }
            }
        }
        return count($map) === 4 ? $map : null;
    }

    private static function stripAccents(string $value): string
    {
        return strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
    }
}
