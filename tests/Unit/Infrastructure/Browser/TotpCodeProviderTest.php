<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Browser;

use App\Infrastructure\Browser\TotpCodeProvider;
use OTPHP\TOTP;
use PHPUnit\Framework\TestCase;

final class TotpCodeProviderTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    public function testGeneratesSixDigitCode(): void
    {
        $code = (new TotpCodeProvider())->generate(self::SECRET);

        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testGeneratedCodeIsVerifiableBySecret(): void
    {
        $code = (new TotpCodeProvider())->generate(self::SECRET);

        self::assertTrue(TOTP::createFromSecret(self::SECRET)->verify($code));
    }

    public function testSecretIsTrimmedAndUppercased(): void
    {
        $provider = new TotpCodeProvider();

        self::assertSame(
            $provider->generate(self::SECRET),
            $provider->generate('  jbswy3dpehpk3pxp  '),
        );
    }

    public function testEmptySecretIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2FA secret key is empty');

        (new TotpCodeProvider())->generate('   ');
    }
}
