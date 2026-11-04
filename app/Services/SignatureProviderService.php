<?php

declare(strict_types=1);

interface SignatureProviderService
{
    public function stampApproval(
        string $sourcePath,
        string $destinationPath,
        string $agreementNumber,
        int $administratorId,
        string $administratorName,
        DateTimeImmutable $signedAtUtc,
        string $originalHash
    ): void;
}
