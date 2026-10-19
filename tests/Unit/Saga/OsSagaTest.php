<?php

use Domain\Core\Domain\Exceptions\DomainException;
use Domain\Saga\Domain\Entities\OsSaga;
use Domain\Saga\Domain\Enums\SagaState;

describe('OsSaga', function () {

    it('começa no diagnóstico com histórico', function () {
        $saga = OsSaga::start(10, 'req-9');

        expect($saga->getState())->toBe(SagaState::Diagnosis)
            ->and($saga->getOsId())->toBe(10)
            ->and($saga->getCorrelationId())->toBe('req-9')
            ->and($saga->getHistory())->toHaveCount(1)
            ->and($saga->getId())->toBeString()
            ->and($saga->toArray()['state'])->toBe('diagnosis');
    });

    it('guarda e remove contexto', function () {
        $saga = OsSaga::start(1, null);
        $saga->remember('payment_id', 'mp-1');

        expect($saga->get('payment_id'))->toBe('mp-1')
            ->and($saga->get('ausente', 'padrao'))->toBe('padrao');

        $saga->forget('payment_id');
        expect($saga->getContext())->toBe([]);
    });

    it('controla prazo do passo atual', function () {
        $saga = OsSaga::start(1, null);
        $deadline = new DateTimeImmutable('2026-01-01 10:00:00');
        $saga->moveTo(SagaState::AwaitingPayment, 'cobrança', $deadline);

        expect($saga->getDeadlineAt())->toBe($deadline)
            ->and($saga->isOverdue(new DateTimeImmutable('2026-01-01 10:01:00')))->toBeTrue()
            ->and($saga->isOverdue(new DateTimeImmutable('2026-01-01 09:59:00')))->toBeFalse();

        $saga->moveTo(SagaState::AwaitingExecution, 'pago');
        expect($saga->isOverdue(new DateTimeImmutable('2030-01-01')))->toBeFalse();
    });

    it('registra a falha e escolhe o estado de compensação', function () {
        $paid = OsSaga::start(1, null);
        $paid->fail('sem estoque', awaitingCompensation: true);

        $unpaid = OsSaga::start(2, null);
        $unpaid->fail('cartão recusado', awaitingCompensation: false);

        expect($paid->getState())->toBe(SagaState::Compensating)
            ->and($paid->getFailureReason())->toBe('sem estoque')
            ->and($unpaid->getState())->toBe(SagaState::Compensated)
            ->and($unpaid->getHistory()[1]['reason'])->toBe('falha: cartão recusado');
    });

    it('não sai de um estado terminal', function () {
        $saga = OsSaga::start(1, null);
        $saga->moveTo(SagaState::Cancelled, 'recusado');

        expect(fn () => $saga->moveTo(SagaState::AwaitingPayment, 'x'))->toThrow(DomainException::class);
    });

    it('marca os estados terminais', function () {
        $terminal = array_values(array_filter(SagaState::cases(), fn ($s) => $s->isTerminal()));

        expect($terminal)->toBe([SagaState::Completed, SagaState::Compensated, SagaState::Cancelled]);
    });
});
