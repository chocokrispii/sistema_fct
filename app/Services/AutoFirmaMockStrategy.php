<?php

declare(strict_types=1);

require_once __DIR__ . '/../Core/ConvenioSignatureMode.php';
require_once __DIR__ . '/SignatureProviderService.php';
require_once __DIR__ . '/PadesSignatureVerifier.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use setasign\Fpdi\Tcpdf\Fpdi;

final class AutoFirmaMockStrategy implements SignatureProviderService
{
    public function sign(string $sourcePdfPath, string $signedPdfPath): void
    {
        if (!ConvenioSignatureMode::canUseMockSignature()) {
            throw new RuntimeException('La firma mock solo está disponible en desarrollo o pruebas.');
        }

        $certificatePath = getenv('FCT_CONVENIO_MOCK_P12_PATH') ?: '';
        $password = getenv('FCT_CONVENIO_MOCK_P12_PASSWORD') ?: '';
        $resolvedCertificate = realpath($certificatePath);
        if (
            $resolvedCertificate === false
            || !is_file($resolvedCertificate)
            || !is_readable($resolvedCertificate)
            || $password === ''
            || !is_readable($sourcePdfPath)
        ) {
            throw new RuntimeException('Falta el certificado PKCS#12 de desarrollo o no se puede leer.');
        }

        $p12 = file_get_contents($resolvedCertificate);
        if (!is_string($p12) || !openssl_pkcs12_read($p12, $credentials, $password)) {
            throw new RuntimeException('No se pudo abrir el certificado PKCS#12 de desarrollo.');
        }
        if (!isset($credentials['cert'], $credentials['pkey']) || !is_string($credentials['cert']) || !is_string($credentials['pkey'])) {
            throw new RuntimeException('El certificado de desarrollo no incluye certificado y clave privada.');
        }

        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($sourcePdfPath);
        if ($pageCount < 1) {
            throw new RuntimeException('El convenio no contiene páginas PDF para firmar.');
        }
        $lastPageSize = null;
        for ($page = 1; $page <= $pageCount; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($template);
            if ($page === $pageCount) {
                $lastPageSize = $size;
            }
        }
        if (!is_array($lastPageSize)) {
            throw new RuntimeException('No se pudo preparar la última página para la firma.');
        }

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY(15, max(15, $lastPageSize['height'] - 32));
        $pdf->Cell(78, 12, 'Firmado digitalmente para pruebas locales', 1, 0, 'C');
        $pdf->setSignature(
            $credentials['cert'],
            $credentials['pkey'],
            '',
            implode("\n", $credentials['extracerts'] ?? []),
            2,
            ['Name' => 'FCT Desarrollo', 'Reason' => 'Firma local de pruebas']
        );
        $pdf->setSignatureAppearance(
            15,
            max(15, $lastPageSize['height'] - 32),
            78,
            12,
            $pageCount
        );
        $pdf->Output($signedPdfPath, 'F');
        if (!is_file($signedPdfPath) || filesize($signedPdfPath) === 0) {
            throw new RuntimeException('TCPDF no produjo el documento firmado de desarrollo.');
        }
        chmod($signedPdfPath, 0600);
    }

    public function verify(string $signedPdfPath): array
    {
        if (!ConvenioSignatureMode::canUseMockSignature()) {
            throw new RuntimeException('La verificación mock solo está disponible en desarrollo o pruebas.');
        }

        $certificatePath = getenv('FCT_CONVENIO_MOCK_P12_PATH') ?: '';
        $password = getenv('FCT_CONVENIO_MOCK_P12_PASSWORD') ?: '';
        $p12 = is_file($certificatePath) ? file_get_contents($certificatePath) : false;
        if (!is_string($p12) || $password === '' || !openssl_pkcs12_read($p12, $credentials, $password)) {
            throw new RuntimeException('No se pudo validar el certificado PKCS#12 de desarrollo.');
        }
        $certificatePem = $credentials['cert'] ?? null;
        if (!is_string($certificatePem)) {
            throw new RuntimeException('El PKCS#12 de desarrollo no incluye un certificado válido.');
        }
        $certificate = openssl_x509_read($certificatePem);
        $publicKey = $certificate === false ? false : openssl_pkey_get_public($certificate);
        $certificateData = $certificate === false ? false : openssl_x509_parse($certificate);
        if (
            $certificate === false
            || $publicKey === false
            || !is_array($certificateData)
            || ($certificateData['issuer'] ?? null) !== ($certificateData['subject'] ?? null)
            || !isset($certificateData['validFrom_time_t'], $certificateData['validTo_time_t'])
            || $certificateData['validFrom_time_t'] > time()
            || $certificateData['validTo_time_t'] < time()
            || openssl_x509_verify($certificate, $publicKey) !== 1
        ) {
            throw new RuntimeException('El certificado de firma local no es autofirmado y vigente.');
        }

        $nssDirectory = getenv('FCT_CONVENIO_MOCK_NSS_DIR') ?: '/var/lib/fct/storage/autofirma-dev/nssdb';
        $validation = (new PadesSignatureVerifier())->verify($signedPdfPath, $nssDirectory, true, true);
        $expectedSigner = $certificateData['subject']['CN'] ?? '';
        if (!is_string($expectedSigner) || !hash_equals($expectedSigner, $validation['signer'])) {
            throw new DomainException('La firma PDF no corresponde al certificado local de desarrollo configurado.');
        }
        return $validation;
    }
}
