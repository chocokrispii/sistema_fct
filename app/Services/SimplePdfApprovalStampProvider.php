<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/SignatureProviderService.php';

use setasign\Fpdi\Fpdi;

final class SimplePdfApprovalStampProvider implements SignatureProviderService
{
    public function stampApproval(
        string $sourcePath,
        string $destinationPath,
        string $agreementNumber,
        int $administratorId,
        string $administratorName,
        DateTimeImmutable $signedAtUtc,
        string $originalHash
    ): void {
        $source = file_get_contents($sourcePath);
        if (!is_string($source) || !str_starts_with($source, '%PDF-')) {
            throw new RuntimeException('El archivo original no es un PDF válido.');
        }
        if (
            preg_match('/\/ByteRange\s*\[[\d\s]+\]/', $source) === 1
            || preg_match('/\/(?:FT\s*\/Sig|Type\s*\/Sig|Annots|AcroForm|XFA)\b/', $source) === 1
        ) {
            throw new DomainException(
                'El PDF contiene una firma digital, formulario o anotación que no se puede preservar. El convenio sigue pendiente y el original no se ha modificado.'
            );
        }

        try {
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($sourcePath);
            if ($pageCount < 1) {
                throw new RuntimeException('El PDF no contiene páginas.');
            }

            for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
                $template = $pdf->importPage($pageNumber);
                $size = $pdf->getTemplateSize($template);
                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($template);
            }

            $this->appendApprovalPage(
                $pdf,
                $agreementNumber,
                $administratorId,
                $administratorName,
                $signedAtUtc,
                $originalHash
            );
            $pdf->Output('F', $destinationPath);
        } catch (DomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RuntimeException('No se pudo procesar el PDF de forma segura.', 0, $exception);
        }
    }

    private function appendApprovalPage(
        Fpdi $pdf,
        string $agreementNumber,
        int $administratorId,
        string $administratorName,
        DateTimeImmutable $signedAtUtc,
        string $originalHash
    ): void {
        $pdf->AddPage('P', 'A4');
        $pdf->SetMargins(22, 24, 22);
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->SetDrawColor(37, 99, 235);
        $pdf->SetFillColor(239, 246, 255);
        $pdf->SetLineWidth(0.7);
        $pdf->Rect(22, 55, 166, 154, 'DF');

        $pdf->SetXY(30, 70);
        $pdf->SetFont('Arial', 'B', 17);
        $pdf->Cell(150, 12, $this->pdfText('APROBACIÓN ELECTRÓNICA SIMPLE'), 0, 1, 'C');

        $pdf->SetXY(35, 91);
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(140, 8, $this->pdfText('Convenio: ' . $agreementNumber), 0, 1);
        $pdf->SetX(35);
        $pdf->SetFont('Arial', '', 11);
        $pdf->MultiCell(140, 8, $this->pdfText('Aprobado por: ' . $administratorName), 0, 'L');
        $pdf->SetX(35);
        $pdf->Cell(140, 8, $this->pdfText('Identificador del administrador: ' . $administratorId), 0, 1);
        $pdf->SetX(35);
        $pdf->Cell(140, 8, $this->pdfText('Fecha y hora UTC: ' . $signedAtUtc->format('Y-m-d\TH:i:s\Z')), 0, 1);

        $pdf->SetXY(35, 141);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(140, 7, $this->pdfText('SHA-256 del documento original'), 0, 1);
        $pdf->SetX(35);
        $pdf->SetFont('Courier', '', 8);
        $pdf->MultiCell(140, 6, $originalHash, 0, 'L');

        $pdf->SetXY(35, 178);
        $pdf->SetFont('Arial', '', 9);
        $pdf->MultiCell(
            140,
            6,
            $this->pdfText('Este sello acredita una aprobación electrónica simple dentro de Sistema FCT. No es una firma digital cualificada ni incorpora un certificado electrónico.'),
            0,
            'L'
        );
    }

    private function pdfText(string $value): string
    {
        $normalized = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        if (!is_string($normalized)) {
            throw new RuntimeException('No se pudo preparar el texto de la aprobación.');
        }
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $normalized);
        if (!is_string($encoded)) {
            throw new RuntimeException('No se pudo codificar el texto de la aprobación.');
        }
        return $encoded;
    }
}
