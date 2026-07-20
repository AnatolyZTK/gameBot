<?php

declare(strict_types=1);

namespace App\Infrastructure\Browser;

use OTPHP\TOTP;
use RuntimeException;

/**
 * Генерирует TOTP-код локально без открытия сторонних сайтов.
 */
final class TotpCodeProvider
{
    public function generate(string $secretKey): string
    {
        $secretKey = strtoupper(trim($secretKey));
        if ($secretKey === '') {
            throw new RuntimeException('2FA secret key is empty');
        }

        $code = TOTP::createFromSecret($secretKey)->now();
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException('Generated TOTP code is invalid');
        }

        return $code;
    }
}
