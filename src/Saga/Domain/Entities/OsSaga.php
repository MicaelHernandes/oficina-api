<?php

namespace Domain\Saga\Domain\Entities;

use Domain\Core\Domain\Exceptions\DomainException;
use Domain\Saga\Domain\Enums\SagaState;
use Illuminate\Support\Str;

/**
 * Instância da saga de uma OS. Guarda o passo atual, o contexto recebido dos
 * outros serviços (orçamento, pagamento, peças reservadas), o prazo do passo
 * corrente e o histórico de transições — base para a compensação e para a
 * auditoria do fluxo distribuído.
 */
class OsSaga
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array<string, mixed>>  $history
     */
    public function __construct(
        private readonly string $id,
        private readonly int $osId,
        private SagaState $state,
        private readonly ?string $correlationId,
        private array $context = [],
        private array $history = [],
        private ?string $failureReason = null,
        private ?\DateTimeImmutable $deadlineAt = null,
    ) {}

    public static function start(int $osId, ?string $correlationId): self
    {
        $saga = new self(
            id: (string) Str::uuid(),
            osId: $osId,
            state: SagaState::Diagnosis,
            correlationId: $correlationId,
        );
        $saga->record(SagaState::Diagnosis, 'saga iniciada');

        return $saga;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOsId(): int
    {
        return $this->osId;
    }

    public function getState(): SagaState
    {
        return $this->state;
    }

    public function getCorrelationId(): ?string
    {
        return $this->correlationId;
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    /** @return array<int, array<string, mixed>> */
    public function getHistory(): array
    {
        return $this->history;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function remember(string $key, mixed $value): void
    {
        $this->context[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->context[$key]);
    }

    public function isIn(SagaState ...$states): bool
    {
        return in_array($this->state, $states, true);
    }

    public function moveTo(SagaState $state, string $reason, ?\DateTimeImmutable $deadlineAt = null): void
    {
        if ($this->state->isTerminal()) {
            throw DomainException::because("Saga {$this->id} já terminou ({$this->state->value}).");
        }

        $this->state = $state;
        $this->deadlineAt = $deadlineAt;
        $this->record($state, $reason);
    }

    /** Inicia a compensação: guarda o motivo e entra em Compensating/Compensated. */
    public function fail(string $reason, bool $awaitingCompensation): void
    {
        $this->failureReason = $reason;
        $this->moveTo(
            $awaitingCompensation ? SagaState::Compensating : SagaState::Compensated,
            "falha: {$reason}",
        );
    }

    public function isOverdue(\DateTimeImmutable $now): bool
    {
        return $this->deadlineAt !== null && $this->deadlineAt < $now;
    }

    private function record(SagaState $state, string $reason): void
    {
        $this->history[] = [
            'at' => (new \DateTimeImmutable)->format(DATE_ATOM),
            'state' => $state->value,
            'reason' => $reason,
        ];
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'os_id' => $this->osId,
            'state' => $this->state->value,
            'correlation_id' => $this->correlationId,
            'context' => $this->context,
            'failure_reason' => $this->failureReason,
            'deadline_at' => $this->deadlineAt?->format(DATE_ATOM),
            'history' => $this->history,
        ];
    }
}
