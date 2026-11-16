<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Uuid;

/**
 * UUID как CHAR(36) вместо BINARY(16) — только для тестов на SQLite.
 *
 * В бою (MySQL) UUID лежат в BINARY(16), но SQLite сравнивает блобы и текст
 * по разным правилам, из-за чего условия вида `WHERE sender_account_id = :account`
 * не находят строк. Логика запросов от представления идентификатора не зависит.
 */
final class StringUuidType extends Type
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL(['length' => 36, 'fixed' => true]);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof AbstractUid) {
            return $value->toRfc4122();
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Uuid::fromString((string) $value)->toRfc4122();
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Uuid
    {
        if ($value instanceof Uuid || $value === null) {
            return $value;
        }

        return Uuid::fromString((string) $value);
    }
}
