<?php

use Domain\Core\Domain\Exceptions\DomainException;
use Domain\Saga\Domain\Messages\Envelope;
use Domain\Saga\Domain\Messages\MessageType;

describe('Envelope', function () {

    it('serializa e desserializa sem perder campos', function () {
        $envelope = Envelope::new(MessageType::RequestPayment, 'saga-1', 'req-1', 42, 'oficina-os-service', ['amount' => 300.0]);

        $copy = Envelope::fromJson($envelope->toJson());

        expect($copy->id)->toBe($envelope->id)
            ->and($copy->type)->toBe('billing.payment.request')
            ->and($copy->sagaId)->toBe('saga-1')
            ->and($copy->correlationId)->toBe('req-1')
            ->and($copy->osId)->toBe(42)
            ->and($copy->payload)->toBe(['amount' => 300.0])
            ->and($copy->messageType())->toBe(MessageType::RequestPayment)
            ->and($copy->occurredAt->format(DATE_ATOM))->toBe($envelope->occurredAt->format(DATE_ATOM));
    });

    it('desembrulha a notificação SNS', function () {
        $envelope = Envelope::new(MessageType::PaymentConfirmed, 's', null, 1, 'billing');
        $sns = json_encode(['Type' => 'Notification', 'Message' => $envelope->toJson()]);

        expect(Envelope::fromJson($sns)->id)->toBe($envelope->id);
    });

    it('aceita campos opcionais ausentes', function () {
        $envelope = Envelope::fromArray(['id' => 'm-1', 'type' => 'x.y.z', 'source' => 'svc', 'payload' => 'invalido']);

        expect($envelope->sagaId)->toBeNull()
            ->and($envelope->osId)->toBeNull()
            ->and($envelope->payload)->toBe([])
            ->and($envelope->messageType())->toBeNull()
            ->and($envelope->logContext())->toBe(['message_id' => 'm-1', 'message_type' => 'x.y.z']);
    });

    it('recusa corpo que não é JSON ou sem campos obrigatórios', function () {
        expect(fn () => Envelope::fromJson('nada'))->toThrow(DomainException::class, 'corpo não é JSON')
            ->and(fn () => Envelope::fromArray(['id' => 'm', 'source' => 's']))->toThrow(DomainException::class, "campo 'type' ausente")
            ->and(fn () => Envelope::fromArray(['id' => '', 'type' => 't', 'source' => 's']))->toThrow(DomainException::class, "campo 'id' ausente");
    });
});
