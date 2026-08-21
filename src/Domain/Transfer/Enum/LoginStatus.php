<?php

declare(strict_types=1);

namespace App\Domain\Transfer\Enum;

enum LoginStatus: string
{
    case Unknown = 'unknown';
    case Ok = 'ok';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'не проверен',
            self::Ok => 'залогинен',
            self::Failed => 'ошибка логина',
            self::Expired => 'сессия истекла',
        };
    }
}
