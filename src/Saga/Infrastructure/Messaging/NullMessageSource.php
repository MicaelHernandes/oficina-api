<?php

namespace Domain\Saga\Infrastructure\Messaging;

use Domain\Saga\Domain\Ports\MessageSource;
use Domain\Saga\Domain\Ports\ReceivedMessage;

/** Sem fila configurada (local/testes): nunca recebe nada. */
class NullMessageSource implements MessageSource
{
    public function receive(): array
    {
        return [];
    }

    public function ack(ReceivedMessage $message): void
    {
        // Nada a confirmar: esta fonte nunca entrega mensagens.
    }
}
