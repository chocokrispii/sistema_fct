<?php

declare(strict_types=1);

final class PadesSignatureVerifier
{
    public function verify(string $pdfPath, string $nssDirectory, bool $skipOcsp = false): array
    {
        if (
            $skipOcsp
            && ((getenv('APP_ENV') ?: 'production') !== 'development'
                || ConvenioSignatureMode::current() !== 'test')
        ) {
            throw new RuntimeException('La omisión OCSP solo se permite para la prueba local de AutoFirma.');
        }

        $resolvedPdf = realpath($pdfPath);
        $resolvedNssDirectory = realpath($nssDirectory);
        if (
            $resolvedPdf === false
            || !is_file($resolvedPdf)
            || !is_readable($resolvedPdf)
            || $resolvedNssDirectory === false
            || !is_dir($resolvedNssDirectory)
            || !is_readable($resolvedNssDirectory)
        ) {
            throw new RuntimeException('No están disponibles el PDF o el almacén de confianza de AutoFirma.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $arguments = ['pdfsig', '-nssdir', 'sql:' . $resolvedNssDirectory, '-aia'];
        if ($skipOcsp) {
            $arguments[] = '-no-ocsp';
        }
        $arguments[] = $resolvedPdf;
        $process = proc_open(
            $arguments,
            $descriptors,
            $pipes,
            null,
            ['LANG' => 'C', 'LC_ALL' => 'C', 'HOME' => sys_get_temp_dir()],
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el verificador de firmas PAdES.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 45;
        $exitCode = null;
        while (true) {
            $output .= stream_get_contents($pipes[1]);
            $output .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException('La validación de la firma PAdES superó el tiempo permitido.');
            }
            usleep(100_000);
        }
        $output .= stream_get_contents($pipes[1]);
        $output .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($process);
        if ($exitCode === null || ($exitCode !== 0 && $closeCode !== 0)) {
            throw new DomainException('No se pudo validar la firma PAdES con el almacén de confianza configurado.');
        }

        return self::parseOutput($output);
    }

    public static function parseOutput(string $output): array
    {
        preg_match_all(
            '/^Signature #\d+:\s*(.*?)(?=^Signature #\d+:|\z)/ms',
            $output,
            $matches
        );
        $signatures = $matches[1] ?? [];
        if ($signatures === []) {
            throw new DomainException('El PDF no contiene una firma digital verificable de AutoFirma.');
        }

        $latest = (string)end($signatures);
        if (
            preg_match('/Signature Validation:\s*Signature is Valid\./i', $latest) !== 1
            || preg_match('/Certificate Validation:\s*Certificate is Trusted\./i', $latest) !== 1
            || preg_match('/Signature Type:\s*(?:ETSI\.CAdES\.detached|adbe\.pkcs7\.detached)\b/i', $latest) !== 1
            || preg_match('/Signing Hash Algorithm:\s*SHA-(?:256|384|512)\b/i', $latest) !== 1
            || preg_match('/Not total document signed/i', $latest) === 1
        ) {
            throw new DomainException(
                'La firma PAdES no es íntegra, el certificado no es de confianza o el PDF contiene cambios posteriores.'
            );
        }

        preg_match('/Signer Certificate Common Name:\s*(.+)/i', $latest, $signerMatch);
        $signer = trim($signerMatch[1] ?? '');
        $signer = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $signer) ?? '';

        return [
            'signer' => mb_substr($signer, 0, 200),
            'signature_count' => count($signatures),
        ];
    }
}
