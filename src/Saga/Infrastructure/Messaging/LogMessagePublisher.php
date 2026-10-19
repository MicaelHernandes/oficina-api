<?php

namespace Domain\Saga\Infrastructure\Messaging;

use Domain\Saga\Domain\Messages\Envelope;
use Domain\Saga\Domain\Ports\MessagePublisher;
use Illuminate\Support\Facades\Log;

/** Desenvolvimento local: só registra a mensagem no log. */
class LogMessagePublisher implements MessagePublisher
{
    public function publish(Envelope $envelope): void
    {
        Log::info('mensagem (driver log)', $envelope->toArray());
    }
}
