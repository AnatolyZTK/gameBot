<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Шина, которая никуда не отправляет, а запоминает сообщения.
 *
 * Мок MessageBusInterface неудобен: `dispatch()` обязан вернуть Envelope,
 * а проверять обычно нужно сами сообщения.
 */
final class CollectingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }

    /** @template T of object @param class-string<T> $class @return list<T> */
    public function messagesOf(string $class): array
    {
        return array_values(array_filter(
            $this->messages,
            static fn (object $message): bool => $message instanceof $class,
        ));
    }
}
