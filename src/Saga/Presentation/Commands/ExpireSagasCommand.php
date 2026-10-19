<?php

namespace Domain\Saga\Presentation\Commands;

use Domain\Saga\Application\OsSagaOrchestrator;
use Illuminate\Console\Command;

/** Agendado a cada minuto: compensa sagas com passo vencido. */
class ExpireSagasCommand extends Command
{
    protected $signature = 'saga:timeouts';

    protected $description = 'Compensa as sagas cujo pagamento ou início de execução não foi confirmado no prazo';

    public function handle(OsSagaOrchestrator $orchestrator): int
    {
        $expired = $orchestrator->expireOverdue(new \DateTimeImmutable);
        $this->line("sagas expiradas: {$expired}");

        return self::SUCCESS;
    }
}
