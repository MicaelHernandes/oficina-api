<?php

namespace Domain\Saga\Domain\Ports;

use Domain\Saga\Domain\Messages\Envelope;

interface MessagePublisher
{
    public function publish(Envelope $envelope): void;
}
