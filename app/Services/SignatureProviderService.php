<?php

declare(strict_types=1);

interface SignatureProviderService
{
    public function verify(string $signedPdfPath): array;
}
