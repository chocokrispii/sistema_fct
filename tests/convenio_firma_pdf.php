<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Services/SimplePdfApprovalStampProvider.php';

function assertConvenioPdf(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fct-convenio-firma-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('No se pudo preparar el directorio temporal de prueba.');
}

$sourcePath = $directory . DIRECTORY_SEPARATOR . 'original.pdf';
$signedPath = $directory . DIRECTORY_SEPARATOR . 'sellado.pdf';
$previouslySignedPath = $directory . DIRECTORY_SEPARATOR . 'con-firma-previa.pdf';

try {
    $original = new \setasign\Fpdi\Fpdi();
    $original->AddPage();
    $original->SetFont('Arial', '', 12);
    $original->Cell(0, 10, 'Contenido original del convenio', 0, 1);
    $original->Output('F', $sourcePath);
    $originalHash = hash_file('sha256', $sourcePath);
    assertConvenioPdf(is_string($originalHash), 'No se pudo calcular el hash original del PDF de prueba.');

    (new SimplePdfApprovalStampProvider())->stampApproval(
        $sourcePath,
        $signedPath,
        'TEST-001',
        42,
        'Administradora de prueba',
        new DateTimeImmutable('2026-03-20T12:34:56+00:00'),
        $originalHash
    );

    $check = new \setasign\Fpdi\Fpdi();
    assertConvenioPdf(
        $check->setSourceFile($signedPath) === 2,
        'El PDF sellado debe conservar la página original y añadir la página de aprobación.'
    );
    assertConvenioPdf(
        hash_file('sha256', $sourcePath) === $originalHash,
        'La generación del sello no debe modificar el PDF original.'
    );

    $signedSource = file_get_contents($sourcePath);
    assertConvenioPdf(is_string($signedSource), 'No se pudo leer el PDF original de prueba.');
    file_put_contents($previouslySignedPath, $signedSource . "\n% /ByteRange [0 1 2 3]\n");
    $rejectedPreviouslySigned = false;
    try {
        (new SimplePdfApprovalStampProvider())->stampApproval(
            $previouslySignedPath,
            $directory . DIRECTORY_SEPARATOR . 'rechazado.pdf',
            'TEST-002',
            42,
            'Administradora de prueba',
            new DateTimeImmutable('2026-03-20T12:34:56+00:00'),
            $originalHash
        );
    } catch (DomainException) {
        $rejectedPreviouslySigned = true;
    }
    assertConvenioPdf(
        $rejectedPreviouslySigned,
        'No se debe reescribir un PDF que contiene una firma digital previa detectable.'
    );
    $formPath = $directory . DIRECTORY_SEPARATOR . 'con-formulario.pdf';
    file_put_contents($formPath, $signedSource . "\n% /AcroForm\n");
    $rejectedForm = false;
    try {
        (new SimplePdfApprovalStampProvider())->stampApproval(
            $formPath,
            $directory . DIRECTORY_SEPARATOR . 'formulario-rechazado.pdf',
            'TEST-003',
            42,
            'Administradora de prueba',
            new DateTimeImmutable('2026-03-20T12:34:56+00:00'),
            $originalHash
        );
    } catch (DomainException) {
        $rejectedForm = true;
    }
    assertConvenioPdf(
        $rejectedForm,
        'No se debe eliminar silenciosamente un formulario PDF interactivo.'
    );

    echo "convenio_firma_pdf: OK\n";
} finally {
    unset($check, $original);
    gc_collect_cycles();
    foreach ([
        $sourcePath,
        $signedPath,
        $previouslySignedPath,
        $directory . DIRECTORY_SEPARATOR . 'rechazado.pdf',
        $directory . DIRECTORY_SEPARATOR . 'con-formulario.pdf',
        $directory . DIRECTORY_SEPARATOR . 'formulario-rechazado.pdf',
    ] as $path) {
        if (is_file($path) && !unlink($path)) {
            error_log('No se pudo retirar un archivo temporal de la prueba de firma PDF.');
        }
    }
    if (is_dir($directory) && !rmdir($directory)) {
        error_log('No se pudo retirar el directorio temporal de la prueba de firma PDF.');
    }
}
