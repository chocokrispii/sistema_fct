<?php

declare(strict_types=1);

final class PdfDocumentGenerator
{
    public static function create(string $title, array $lines): string
    {
        $textLines = array_merge([$title, 'Sistema FCT'], $lines);
        $commands = ['BT', '/F1 16 Tf', '50 780 Td'];
        foreach ($textLines as $index => $line) {
            $safe = self::escape((string)$line);
            if ($index > 0) $commands[] = '0 -28 Td';
            $commands[] = '/F1 ' . ($index === 0 ? '16' : '11') . ' Tf';
            $commands[] = '(' . $safe . ') Tj';
        }
        $commands[] = 'ET';
        $stream = implode("\n", $commands) . "\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream',
        ];
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $number => $object) {
            $offsets[$number + 1] = strlen($pdf);
            $pdf .= ($number + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($index = 1; $index <= count($objects); $index++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    private static function escape(string $value): string
    {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        $value = is_string($converted) ? $converted : $value;
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $value);
    }
}
