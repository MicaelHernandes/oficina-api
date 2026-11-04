<?php

namespace Domain\Saga\Domain\Messages;

/**
 * Contrato das mensagens trocadas na saga. O prefixo indica o serviço que
 * consome o comando (billing., execution.) ou que publica o evento; as
 * assinaturas SNS → SQS filtram pelo atributo `type`.
 */
enum MessageType: string
{
    // ── Eventos publicados pelo OS Service ───────────────────────────
    case OsOpened = 'os.order.opened';
    case OsCancelled = 'os.order.cancelled';
    case OsCompleted = 'os.order.completed';

    // ── Comandos do OS Service para o Billing ────────────────────────
    case GenerateBudget = 'billing.budget.generate';
    case CancelBudget = 'billing.budget.cancel';
    case RequestPayment = 'billing.payment.request';
    case CancelPayment = 'billing.payment.cancel';
    case RefundPayment = 'billing.payment.refund';

    // ── Comandos do OS Service para o Execution ──────────────────────
    case EnqueueExecution = 'execution.job.enqueue';
    case CancelExecution = 'execution.job.cancel';

    // ── Eventos do Billing consumidos pelo OS Service ────────────────
    case BudgetGenerated = 'billing.budget.generated';
    case BudgetApproved = 'billing.budget.approved';
    case BudgetRejected = 'billing.budget.rejected';
    case PaymentConfirmed = 'billing.payment.confirmed';
    case PaymentFailed = 'billing.payment.failed';
    case PaymentRefunded = 'billing.payment.refunded';

    // ── Eventos do Execution consumidos pelo OS Service ──────────────
    case ExecutionStarted = 'execution.job.started';
    case ExecutionFinished = 'execution.job.finished';
    case ExecutionFailed = 'execution.job.failed';
}
