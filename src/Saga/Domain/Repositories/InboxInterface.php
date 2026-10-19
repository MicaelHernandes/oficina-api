<?php

namespace Domain\Saga\Domain\Repositories;

use Domain\Saga\Domain\Messages\Envelope;

/**
 * Idempotência do consumidor: SQS entrega "pelo menos uma vez", então a
 * mesma mensagem pode chegar de novo.
 */
interface InboxInterface
{
    /** Registra a mensagem; devolve false se ela já foi processada. */
    public function remember(Envelope $envelope): bool;
}
