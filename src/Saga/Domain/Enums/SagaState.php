<?php

namespace Domain\Saga\Domain\Enums;

/**
 * Estados da saga da OS (orquestrada pelo OS Service).
 *
 * Caminho feliz:
 *   Diagnosis → AwaitingApproval → AwaitingPayment → AwaitingExecution
 *   → Executing → Completed
 *
 * Falhas:
 *   - pagamento recusado/expirado      → Compensated (cancela orçamento)
 *   - sem estoque / execução falhou    → Compensating (estorno pedido)
 *                                      → Compensated (estorno confirmado)
 *   - cliente recusa de vez o orçamento → Cancelled (nada a desfazer)
 */
enum SagaState: string
{
    case Diagnosis = 'diagnosis';
    case AwaitingApproval = 'awaiting_approval';
    case AwaitingPayment = 'awaiting_payment';
    case AwaitingExecution = 'awaiting_execution';
    case Executing = 'executing';
    case Completed = 'completed';
    case Compensating = 'compensating';
    case Compensated = 'compensated';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Compensated, self::Cancelled => true,
            default => false,
        };
    }
}
