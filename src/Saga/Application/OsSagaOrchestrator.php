<?php

namespace Domain\Saga\Application;

use Domain\Catalog\Domain\Repositories\PartRepositoryInterface;
use Domain\Core\Domain\Exceptions\DomainException;
use Domain\Customer\Domain\Repositories\CustomerRepositoryInterface;
use Domain\Saga\Domain\Entities\OsSaga;
use Domain\Saga\Domain\Enums\SagaState;
use Domain\Saga\Domain\Messages\Envelope;
use Domain\Saga\Domain\Messages\MessageType;
use Domain\Saga\Domain\Repositories\InboxInterface;
use Domain\Saga\Domain\Repositories\OutboxInterface;
use Domain\Saga\Domain\Repositories\SagaRepositoryInterface;
use Domain\Workshop\Application\Concerns\DispatchesOsEvents;
use Domain\Workshop\Domain\Entities\OrderService;
use Domain\Workshop\Domain\Enums\OsStatus;
use Domain\Workshop\Domain\Repositories\OrderServiceRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orquestrador da Saga da OS (OS Service).
 *
 *   OS aberta ─▶ diagnóstico/orçamento (local) ─cmd▶ Billing: gerar orçamento
 *   Billing: aprovado ─cmd▶ Billing: cobrar (Mercado Pago)
 *   Billing: pago ─▶ reserva de peças (local) ─cmd▶ Execution: enfileirar
 *   Execution: iniciou / finalizou ─▶ OS em execução / execução finalizada
 *
 * Compensações:
 *   - pagamento recusado ou vencido → cancela orçamento/cobrança, OS cancelada
 *   - sem estoque, execução falhou ou não confirmada → devolve peças, pede
 *     estorno ao Billing, OS cancelada; a saga fecha quando o estorno chega
 *
 * Cada mensagem é processada numa transação: inbox (idempotência) + estado da
 * saga + OS + outbox. Os comandos só saem depois do commit (`saga:relay`).
 */
class OsSagaOrchestrator
{
    use DispatchesOsEvents;

    /** Tipo de mensagem → [handler, estados da saga em que é aceita]. */
    private const HANDLERS = [
        'billing.budget.generated' => ['onBudgetGenerated', [SagaState::AwaitingApproval]],
        'billing.budget.approved' => ['onBudgetApproved', [SagaState::AwaitingApproval]],
        'billing.budget.rejected' => ['onBudgetRejected', [SagaState::AwaitingApproval]],
        'billing.payment.confirmed' => ['onPaymentConfirmed', [SagaState::AwaitingPayment]],
        'billing.payment.failed' => ['onPaymentFailed', [SagaState::AwaitingPayment]],
        'billing.payment.refunded' => ['onPaymentRefunded', [SagaState::Compensating]],
        'execution.job.started' => ['onExecutionStarted', [SagaState::AwaitingExecution]],
        'execution.job.finished' => ['onExecutionFinished', [SagaState::AwaitingExecution, SagaState::Executing]],
        'execution.job.failed' => ['onExecutionFailed', [SagaState::AwaitingExecution, SagaState::Executing]],
    ];

    /** @var array<int, array{0: OrderService, 1: OsStatus}> notificações a disparar após o commit */
    private array $statusChanges = [];

    public function __construct(
        private readonly SagaRepositoryInterface $sagas,
        private readonly OutboxInterface $outbox,
        private readonly InboxInterface $inbox,
        private readonly OrderServiceRepositoryInterface $orders,
        private readonly PartRepositoryInterface $parts,
        private readonly CustomerRepositoryInterface $customers,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('messaging.saga_enabled');
    }

    // ── Início e passos disparados pelo próprio OS Service ────────────

    /** Abertura da OS: cria a saga e anuncia a OS aos demais serviços. */
    public function start(OrderService $os, ?string $correlationId): OsSaga
    {
        return DB::transaction(function () use ($os, $correlationId) {
            $saga = OsSaga::start($os->getId(), $correlationId);
            $this->emit($saga, MessageType::OsOpened, [
                'customer_id' => $os->getCustomerId(),
                'vehicle_id' => $os->getVehicleId(),
                'complaint' => $os->getComplaint(),
            ]);
            $this->sagas->save($saga);

            Log::info('saga iniciada', ['saga_id' => $saga->getId(), 'os_id' => $os->getId()]);

            return $saga;
        });
    }

    /**
     * Diagnóstico concluído (orçamento montado localmente com os preços do
     * catálogo): pede ao Billing que gere o orçamento oficial e o envie ao
     * cliente. Também usado na renegociação, com uma nova revisão.
     */
    public function requestBudget(OrderService $os, ?string $correlationId): void
    {
        DB::transaction(function () use ($os, $correlationId) {
            $saga = $this->sagas->findByOsId($os->getId(), lock: true)
                ?? OsSaga::start($os->getId(), $correlationId);

            if (! $saga->isIn(SagaState::Diagnosis)) {
                throw DomainException::because(
                    "A saga da OS #{$os->getId()} está em '{$saga->getState()->value}'; não é possível gerar orçamento agora."
                );
            }

            $revision = (int) $saga->get('budget_revision', 0) + 1;
            $saga->remember('budget_revision', $revision);
            $saga->moveTo(SagaState::AwaitingApproval, "orçamento (revisão {$revision}) enviado ao Billing");

            $this->emit($saga, MessageType::GenerateBudget, $this->budgetPayload($os, $revision));
            $this->sagas->save($saga);
        });
    }

    // ── Mensagens recebidas do Billing e do Execution ─────────────────

    public function handle(Envelope $message): void
    {
        $handler = self::HANDLERS[$message->type] ?? null;

        if ($handler === null) {
            Log::info('mensagem ignorada: tipo sem handler na saga', $message->logContext());

            return;
        }

        [$method, $acceptedStates] = $handler;
        $this->statusChanges = [];

        DB::transaction(function () use ($message, $method, $acceptedStates) {
            if (! $this->inbox->remember($message)) {
                Log::info('mensagem duplicada ignorada', $message->logContext());

                return;
            }

            $saga = $this->findSagaFor($message);
            if ($saga === null) {
                Log::warning('mensagem sem saga correspondente', $message->logContext());

                return;
            }

            if (! $saga->isIn(...$acceptedStates)) {
                Log::warning('mensagem fora de ordem para o estado da saga', $message->logContext() + [
                    'saga_state' => $saga->getState()->value,
                ]);

                return;
            }

            $os = $this->orders->findById($saga->getOsId())
                ?? throw DomainException::because("OS #{$saga->getOsId()} da saga {$saga->getId()} não existe.");

            $this->{$method}($saga, $os, $message->payload);

            $this->sagas->save($saga);
            Log::info('saga avançou', $message->logContext() + ['saga_state' => $saga->getState()->value]);
        });

        $this->flushStatusNotifications();
    }

    /** Compensa as sagas cujo passo dependente de outro serviço venceu. */
    public function expireOverdue(\DateTimeImmutable $now): int
    {
        $expired = 0;

        foreach ($this->sagas->findOverdue($now) as $candidate) {
            $this->statusChanges = [];

            DB::transaction(function () use ($candidate, $now, &$expired) {
                $saga = $this->sagas->findByOsId($candidate->getOsId(), lock: true);
                if ($saga === null || ! $saga->isOverdue($now)) {
                    return;
                }

                $os = $this->orders->findById($saga->getOsId());
                if ($os === null) {
                    return;
                }

                if ($saga->isIn(SagaState::AwaitingPayment)) {
                    $this->emit($saga, MessageType::CancelPayment, ['reason' => 'payment_timeout']);
                    $this->cancelOrder($saga, $os, 'pagamento não confirmado no prazo', awaitingCompensation: false);
                } elseif ($saga->isIn(SagaState::AwaitingExecution)) {
                    $this->emit($saga, MessageType::CancelExecution, ['reason' => 'execution_ack_timeout']);
                    $this->compensatePaidOrder($saga, $os, 'execução não confirmada no prazo');
                } else {
                    return;
                }

                $this->sagas->save($saga);
                $expired++;
                Log::warning('saga expirada', ['saga_id' => $saga->getId(), 'os_id' => $saga->getOsId()]);
            });

            $this->flushStatusNotifications();
        }

        return $expired;
    }

    // ── Handlers ──────────────────────────────────────────────────────

    private function onBudgetGenerated(OsSaga $saga, OrderService $os, array $payload): void
    {
        $saga->remember('budget_id', $payload['budget_id'] ?? null);
    }

    private function onBudgetApproved(OsSaga $saga, OrderService $os, array $payload): void
    {
        $this->changeStatus($os, fn () => $os->getStatus() === OsStatus::InRenegotiation
            ? $os->approveRenegotiation()
            : $os->approveBudget());

        $saga->remember('budget_id', $payload['budget_id'] ?? $saga->get('budget_id'));
        $saga->moveTo(
            SagaState::AwaitingPayment,
            'orçamento aprovado; cobrança solicitada',
            $this->deadline('payment_minutes'),
        );

        $this->emit($saga, MessageType::RequestPayment, [
            'budget_id' => $saga->get('budget_id'),
            'amount' => $os->getBudget()?->getTotalAmount() ?? 0.0,
            'customer' => $this->customerPayload($os),
        ]);
    }

    private function onBudgetRejected(OsSaga $saga, OrderService $os, array $payload): void
    {
        if (! empty($payload['final'])) {
            // Recusa definitiva: nada foi cobrado nem reservado.
            $this->changeStatus($os, fn () => $os->transitionTo(OsStatus::Rejected));
            $saga->moveTo(SagaState::Cancelled, 'orçamento recusado pelo cliente');
            $this->emit($saga, MessageType::OsCancelled, ['reason' => 'budget_rejected']);

            return;
        }

        // Recusa com renegociação: volta ao diagnóstico para nova proposta.
        $this->changeStatus($os, fn () => $os->rejectBudget());
        $saga->moveTo(SagaState::Diagnosis, 'orçamento recusado; aguardando nova proposta');
    }

    private function onPaymentConfirmed(OsSaga $saga, OrderService $os, array $payload): void
    {
        $saga->remember('payment_id', $payload['payment_id'] ?? null);

        try {
            $this->reserveParts($saga, $os);
        } catch (DomainException $e) {
            $this->compensatePaidOrder($saga, $os, $e->getMessage());

            return;
        }

        $saga->moveTo(
            SagaState::AwaitingExecution,
            'pagamento confirmado; peças reservadas; enviado para execução',
            $this->deadline('execution_ack_minutes'),
        );

        $this->emit($saga, MessageType::EnqueueExecution, [
            'vehicle_id' => $os->getVehicleId(),
            'mechanic_user_id' => $os->getMechanicUserId(),
            'complaint' => $os->getComplaint(),
            'services' => array_map(fn ($i) => $i->toArray(), $os->getServiceItems()),
            'parts' => array_map(fn ($i) => $i->toArray(), $os->getPartItems()),
        ]);
    }

    private function onPaymentFailed(OsSaga $saga, OrderService $os, array $payload): void
    {
        $this->emit($saga, MessageType::CancelBudget, ['reason' => 'payment_failed']);
        $this->cancelOrder($saga, $os, 'pagamento recusado: '.($payload['reason'] ?? 'sem motivo'), awaitingCompensation: false);
    }

    private function onPaymentRefunded(OsSaga $saga, OrderService $os, array $payload): void
    {
        $saga->moveTo(SagaState::Compensated, 'estorno confirmado pelo Billing');
    }

    private function onExecutionStarted(OsSaga $saga, OrderService $os, array $payload): void
    {
        $this->changeStatus($os, function () use ($os) {
            $os->startExecution();
            $os->markStartedAt(new \DateTimeImmutable);
        });
        $saga->moveTo(SagaState::Executing, 'execução iniciada');
    }

    private function onExecutionFinished(OsSaga $saga, OrderService $os, array $payload): void
    {
        $this->changeStatus($os, function () use ($os) {
            // O "started" pode ter se perdido ou chegado depois: a OS passa
            // pela execução para respeitar a máquina de estados.
            if ($os->getStatus() === OsStatus::Approved) {
                $os->startExecution();
                $os->markStartedAt(new \DateTimeImmutable);
            }
            $os->finishExecution();
            $os->markFinishedAt(new \DateTimeImmutable);
        });

        $saga->forget('reserved_parts');
        $saga->moveTo(SagaState::Completed, 'execução finalizada');
        $this->emit($saga, MessageType::OsCompleted, []);
    }

    private function onExecutionFailed(OsSaga $saga, OrderService $os, array $payload): void
    {
        $this->compensatePaidOrder($saga, $os, 'execução falhou: '.($payload['reason'] ?? 'sem motivo'));
    }

    // ── Compensações ──────────────────────────────────────────────────

    /** Depois do pagamento: devolve as peças, pede o estorno e cancela a OS. */
    private function compensatePaidOrder(OsSaga $saga, OrderService $os, string $reason): void
    {
        $this->releaseParts($saga);
        $this->emit($saga, MessageType::RefundPayment, [
            'payment_id' => $saga->get('payment_id'),
            'reason' => $reason,
        ]);
        $this->cancelOrder($saga, $os, $reason, awaitingCompensation: true);
    }

    private function cancelOrder(OsSaga $saga, OrderService $os, string $reason, bool $awaitingCompensation): void
    {
        $this->changeStatus($os, fn () => $os->transitionTo(OsStatus::Rejected));
        $saga->fail($reason, $awaitingCompensation);
        $this->emit($saga, MessageType::OsCancelled, ['reason' => $reason]);
    }

    /** Reserva tudo ou nada: valida o estoque de todas as peças antes de baixar. */
    private function reserveParts(OsSaga $saga, OrderService $os): void
    {
        $toReserve = [];
        $missing = [];

        foreach ($os->getPartItems() as $item) {
            $part = $this->parts->findById($item->getPartId());
            if ($part === null || ! $part->hasStock($item->getQuantity())) {
                $missing[] = $item->getPartName();

                continue;
            }
            $toReserve[] = [$part, $item->getQuantity()];
        }

        if ($missing !== []) {
            throw DomainException::because('estoque insuficiente para: '.implode(', ', $missing));
        }

        $reserved = [];
        foreach ($toReserve as [$part, $quantity]) {
            $part->decrementStock($quantity);
            $this->parts->save($part);
            $reserved[] = ['part_id' => $part->getId(), 'quantity' => $quantity];
        }

        $saga->remember('reserved_parts', $reserved);
    }

    private function releaseParts(OsSaga $saga): void
    {
        foreach ($saga->get('reserved_parts', []) as $reserved) {
            $part = $this->parts->findById((int) $reserved['part_id']);
            if ($part === null) {
                continue;
            }
            $part->incrementStock((int) $reserved['quantity']);
            $this->parts->save($part);
        }

        $saga->forget('reserved_parts');
    }

    // ── Apoio ─────────────────────────────────────────────────────────

    private function findSagaFor(Envelope $message): ?OsSaga
    {
        if ($message->osId !== null) {
            return $this->sagas->findByOsId($message->osId, lock: true);
        }

        $saga = $message->sagaId !== null ? $this->sagas->findById($message->sagaId) : null;

        return $saga !== null ? $this->sagas->findByOsId($saga->getOsId(), lock: true) : null;
    }

    private function changeStatus(OrderService $os, callable $transition): void
    {
        $previous = $os->getStatus();
        $transition();
        $this->orders->save($os);
        $this->statusChanges[] = [$os, $previous];
    }

    private function flushStatusNotifications(): void
    {
        foreach ($this->statusChanges as [$os, $previous]) {
            $this->dispatchOsStatusUpdated($os, $previous, $this->customers);
        }
        $this->statusChanges = [];
    }

    private function emit(OsSaga $saga, MessageType $type, array $payload): void
    {
        $this->outbox->add(Envelope::new(
            type: $type,
            sagaId: $saga->getId(),
            correlationId: $saga->getCorrelationId(),
            osId: $saga->getOsId(),
            source: (string) config('messaging.source'),
            payload: $payload,
        ));
    }

    private function deadline(string $key): \DateTimeImmutable
    {
        $minutes = (int) config("messaging.timeouts.{$key}");

        return (new \DateTimeImmutable)->modify("+{$minutes} minutes");
    }

    private function budgetPayload(OrderService $os, int $revision): array
    {
        $budget = $os->getBudget();

        return [
            'revision' => $revision,
            'customer' => $this->customerPayload($os),
            'services' => array_map(fn ($i) => $i->toArray(), $os->getServiceItems()),
            'parts' => array_map(fn ($i) => $i->toArray(), $os->getPartItems()),
            'total_services' => $budget?->getTotalServices() ?? 0.0,
            'total_parts' => $budget?->getTotalParts() ?? 0.0,
            'total_amount' => $budget?->getTotalAmount() ?? 0.0,
            'notes' => $budget?->getNotes(),
        ];
    }

    private function customerPayload(OrderService $os): ?array
    {
        $customer = $this->customers->findById($os->getCustomerId());

        return $customer === null ? null : [
            'id' => $customer->getId(),
            'name' => $customer->getName(),
            'email' => $customer->getEmail(),
            'document' => $customer->getDocument()->value(),
        ];
    }
}
