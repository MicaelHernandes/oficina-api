<?php

namespace Domain\Saga\Presentation\Commands;

use Domain\Saga\Application\MessageConsumer;
use Illuminate\Console\Command;

/** Consumidor da fila SQS do OS Service (Deployment oficina-saga-worker). */
class ConsumeMessagesCommand extends Command
{
    protected $signature = 'saga:consume
        {--once : Processa um único lote e sai}
        {--max-time=3600 : Encerra após N segundos (o Deployment reinicia o processo)}';

    protected $description = 'Consome os eventos do Billing/Execution e avança a saga das OS';

    private bool $shouldStop = false;

    public function handle(MessageConsumer $consumer): int
    {
        $this->trap([SIGTERM, SIGINT], fn () => $this->shouldStop = true);
        $deadline = time() + (int) $this->option('max-time');

        do {
            $result = $consumer->consumeBatch();

            if ($result['processed'] + $result['failed'] > 0) {
                $this->line("processadas: {$result['processed']} | com falha: {$result['failed']}");
            }
        } while (! $this->option('once') && ! $this->shouldStop && time() < $deadline);

        return self::SUCCESS;
    }
}
