<?php

declare(strict_types=1);

namespace App\Tests\Integration\Transfer;

use App\Application\Transfer\Service\AccountIdentifierResolver;
use App\Domain\Transfer\Enum\Platform;
use App\Infrastructure\Persistence\Entity\Account;
use App\Tests\Support\DoctrineTestCase;
use Symfony\Component\Uid\Uuid;

final class AccountIdentifierResolverTest extends DoctrineTestCase
{
    private AccountIdentifierResolver $resolver;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new AccountIdentifierResolver($this->accountRepository());
        $this->account = new Account('player@example.com', Platform::Ps);
        $this->entityManager->persist($this->account);
        $this->entityManager->flush();
    }

    public function testResolvesByEmail(): void
    {
        self::assertTrue($this->resolver->resolve('player@example.com')->getId()->equals($this->account->getId()));
    }

    public function testResolvesByEmailIgnoringCaseAndSpaces(): void
    {
        self::assertTrue($this->resolver->resolve('  PLAYER@Example.com  ')->getId()->equals($this->account->getId()));
    }

    public function testResolvesByUuid(): void
    {
        self::assertTrue(
            $this->resolver->resolve($this->account->getId()->toRfc4122())->getId()->equals($this->account->getId()),
        );
    }

    public function testEmptyIdentifierIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Account identifier is empty.');

        $this->resolver->resolve('   ');
    }

    public function testUnknownEmailIsReported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Account not found: nobody@example.com');

        $this->resolver->resolve('nobody@example.com');
    }

    public function testUnknownUuidIsReported(): void
    {
        $uuid = Uuid::v7()->toRfc4122();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Account not found: '.$uuid);

        $this->resolver->resolve($uuid);
    }
}
