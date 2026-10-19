<?php

namespace Domain\Saga\Application;

use Domain\Core\Domain\Exceptions\DomainException;
use Domain\Saga\Domain\Messages\Envelope;
use Domain\Saga\Domain\Ports\MessageSource;
use Illuminate\Support\Facades\Log;

/**
 * Lê um lote da fila e entrega cada mensagem ao orquestrador. Só confirma
 * (ack) o que foi processado; o resto volta para a fila e, depois do limite
 * de tentativas da redrive policy, cai na DLQ.
 */
class MessageConsumer
{
    public function __construct(
        private readonly MessageSource $source,
        private readonly OsSagaOrchestrator $orchestrator,
    ) {}

    /** @return array{processed: int, failed: int} */
    public function consumeBatch(): array
    {
        $processed = 0;
        $failed = 0;

        foreach ($this->source->receive() as $received) {
            try {
                $envelope = Envelope::fromJson($received->body);
            } catch (DomainException $e) {
                // Mensagem malformada nunca vai dar certo: descarta.
                Log::error('mensagem malformada descartada', ['error' => $e->getMessage()]);
                $this->source->ack($received);

                continue;
            }

            Log::withContext($envelope->logContext());

            try {
                $this->orchestrator->handle($envelope);
                $this->source->ack($received);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::error('falha ao processar mensagem; volta para a fila', $envelope->logContext() + [
                    'error' => $e->getMessage(),
                ]);
            } finally {
                Log::withoutContext();
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }
}
