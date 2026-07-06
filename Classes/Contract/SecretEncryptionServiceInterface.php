<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Contract;

interface SecretEncryptionServiceInterface
{
    public function encrypt(string $plainText): string;
    public function decrypt(string $encryptedValue): string;
}
