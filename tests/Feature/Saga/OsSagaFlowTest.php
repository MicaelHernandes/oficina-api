<?php

use App\Enums\UserRole;
use App\Models\User;
use Domain\Catalog\Infrastructure\Models\PartModel;
use Domain\Catalog\Infrastructure\Models\ServiceModel;
use Domain\Customer\Infrastructure\Models\CustomerModel;
use Domain\Customer\Infrastructure\Models\VehicleModel;
use Domain\Saga\Application\OsSagaOrchestrator;
use Domain\Saga\Domain\Messages\Envelope;
use Domain\Saga\Domain\Messages\MessageType;
use Domain\Saga\Infrastructure\Models\OutboxMessageModel;
use Domain\Saga\Infrastructure\Models\SagaInstanceModel;
use Domain\Workshop\Infrastructure\Models\OrderServiceModel;
use Illuminate\Support\Facades\Mail;

/** Evento recebido do Billing/Execution para a OS. */
function sagaEvent(MessageType $type, int $osId, array $payload = []): Envelope
{
    $saga = SagaInstanceModel::where('os_id', $osId)->firstOrFail();

    return Envelope::new($type, $saga->id, $saga->correlation_id, $osId, 'test-suite', $payload);
}

function sagaDeliver(MessageType $type, int $osId, array $payload = []): Envelope
{
    $event = sagaEvent($type, $osId, $payload);
    app(OsSagaOrchestrator::class)->handle($event);

    return $event;
}

function sagaState(int $osId): string
{
    return SagaInstanceModel::where('os_id', $osId)->value('state');
}

function osStatus(int $osId): string
{
    return OrderServiceModel::whereKey($osId)->value('status');
}

/** @return string[] tipos gravados no outbox para a OS, em ordem */
function outboxTypes(int $osId): array
{
    return OutboxMessageModel::orderBy('id')->get()
        ->filter(fn ($m) => ($m->body['os_id'] ?? null) === $osId)
        ->pluck('type')->values()->all();
}

function lastOutbox(MessageType $type): array
{
    return OutboxMessageModel::where('type', $type->value)->orderByDesc('id')->firstOrFail()->body;
}

describe('Saga da OS (orquestrada)', function () {

    beforeEach(function () {
        Mail::fake();
        config(['messaging.saga_enabled' => true]);

        $this->attendant = User::factory()->create(['role' => UserRole::Attendant]);

        $this->customer = CustomerModel::create([
            'name' => 'Cliente Saga',
            'document' => '52998224725',
            'email' => 'saga@test.com',
        ]);

        $this->vehicle = VehicleModel::create([
            'customer_id' => $this->customer->id,
            'plate' => 'SAG-0001',
            'brand' => 'Fiat',
            'model' => 'Argo',
            'year' => 2023,
            'color' => 'Branco',
        ]);

        $this->service = ServiceModel::create([
            'name' => 'Revisão',
            'base_price' => 200.00,
            'estimated_minutes' => 60,
            'is_active' => true,
        ]);

        $this->part = PartModel::create([
            'code' => 'SAGA-PART-1',
            'name' => 'Pastilha de freio',
            'unit_price' => 50.00,
            'stock_quantity' => 5,
            'minimum_stock' => 1,
            'unit' => 'un',
        ]);

        // Abre a OS e gera o orçamento (2 pastilhas + 1 revisão = R$ 300).
        $this->osId = $this->actingAs($this->attendant, 'sanctum')
            ->withHeader('X-Request-Id', 'req-saga-123')
            ->postJson('/api/order-services', [
                'customer_id' => $this->customer->id,
                'vehicle_id' => $this->vehicle->id,
                'complaint' => 'Freio rangendo',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->generateBudget = function (int $partQuantity = 2) {
            return $this->actingAs($this->attendant, 'sanctum')
                ->postJson("/api/order-services/{$this->osId}/generate-budget", [
                    'services' => [['service_id' => $this->service->id, 'quantity' => 1]],
                    'parts' => [['part_id' => $this->part->id, 'quantity' => $partQuantity]],
                ]);
        };

        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/order-services/{$this->osId}/send-to-analysis")
            ->assertOk();
    });

    it('inicia a saga na abertura da OS com o X-Request-Id como correlation id', function () {
        $saga = SagaInstanceModel::where('os_id', $this->osId)->firstOrFail();

        expect($saga->state)->toBe('diagnosis')
            ->and($saga->correlation_id)->toBe('req-saga-123')
            ->and(outboxTypes($this->osId))->toBe(['os.order.opened']);
    });

    it('percorre o caminho feliz até a execução finalizada', function () {
        ($this->generateBudget)()->assertOk()->assertJsonPath('data.status', 'pending_approval');

        expect(sagaState($this->osId))->toBe('awaiting_approval');
        $budget = lastOutbox(MessageType::GenerateBudget);
        expect($budget['payload']['total_amount'])->toEqual(300.0)
            ->and($budget['payload']['revision'])->toBe(1)
            ->and($budget['payload']['customer']['email'])->toBe('saga@test.com')
            ->and($budget['correlation_id'])->toBe('req-saga-123');

        sagaDeliver(MessageType::BudgetGenerated, $this->osId, ['budget_id' => 'bdg-1']);
        sagaDeliver(MessageType::BudgetApproved, $this->osId, ['budget_id' => 'bdg-1']);

        expect(osStatus($this->osId))->toBe('approved')
            ->and(sagaState($this->osId))->toBe('awaiting_payment');
        $payment = lastOutbox(MessageType::RequestPayment);
        expect($payment['payload']['budget_id'])->toBe('bdg-1')
            ->and($payment['payload']['amount'])->toEqual(300.0);

        sagaDeliver(MessageType::PaymentConfirmed, $this->osId, ['payment_id' => 'mp-999']);

        expect(sagaState($this->osId))->toBe('awaiting_execution')
            ->and($this->part->fresh()->stock_quantity)->toBe(3);
        $job = lastOutbox(MessageType::EnqueueExecution);
        expect($job['payload']['parts'])->toHaveCount(1)
            ->and($job['payload']['services'])->toHaveCount(1);

        sagaDeliver(MessageType::ExecutionStarted, $this->osId);
        expect(osStatus($this->osId))->toBe('in_execution')
            ->and(sagaState($this->osId))->toBe('executing');

        sagaDeliver(MessageType::ExecutionFinished, $this->osId);
        expect(osStatus($this->osId))->toBe('execution_finished')
            ->and(sagaState($this->osId))->toBe('completed')
            ->and(OrderServiceModel::find($this->osId)->finished_at)->not->toBeNull()
            ->and(outboxTypes($this->osId))->toBe([
                'os.order.opened',
                'billing.budget.generate',
                'billing.payment.request',
                'execution.job.enqueue',
                'os.order.completed',
            ]);

        // A entrega continua local.
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/order-services/{$this->osId}/deliver")
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered_and_finalized');
    });

    it('bloqueia as transições locais conduzidas pelo Billing e pelo Execution', function () {
        ($this->generateBudget)()->assertOk();

        foreach (['approve-budget', 'reject-budget', 'reject-renegotiation', 'start-execution', 'finish-execution'] as $action) {
            $this->actingAs($this->attendant, 'sanctum')
                ->postJson("/api/order-services/{$this->osId}/{$action}")
                ->assertStatus(422)
                ->assertJsonFragment(['message' => match ($action) {
                    'approve-budget' => 'Com a saga ligada, a aprovação do orçamento fica a cargo do Billing Service.',
                    'start-execution' => 'Com a saga ligada, o início da execução fica a cargo do Execution Service.',
                    'finish-execution' => 'Com a saga ligada, o fim da execução fica a cargo do Execution Service.',
                    default => 'Com a saga ligada, a recusa do orçamento fica a cargo do Billing Service.',
                }]);
        }

        expect(osStatus($this->osId))->toBe('pending_approval');
    });

    it('compensa com estorno quando falta estoque após o pagamento', function () {
        ($this->generateBudget)(4)->assertOk();
        sagaDeliver(MessageType::BudgetApproved, $this->osId, ['budget_id' => 'bdg-2']);

        // Outra OS consumiu o estoque enquanto o cliente pagava.
        $this->part->update(['stock_quantity' => 1]);

        sagaDeliver(MessageType::PaymentConfirmed, $this->osId, ['payment_id' => 'mp-1']);

        expect(osStatus($this->osId))->toBe('rejected')
            ->and(sagaState($this->osId))->toBe('compensating')
            ->and($this->part->fresh()->stock_quantity)->toBe(1);

        $refund = lastOutbox(MessageType::RefundPayment);
        expect($refund['payload']['payment_id'])->toBe('mp-1')
            ->and($refund['payload']['reason'])->toContain('estoque insuficiente');

        sagaDeliver(MessageType::PaymentRefunded, $this->osId, ['payment_id' => 'mp-1']);

        $saga = SagaInstanceModel::where('os_id', $this->osId)->first();
        expect($saga->state)->toBe('compensated')
            ->and($saga->failure_reason)->toContain('estoque insuficiente');
    });

    it('devolve as peças e pede estorno quando a execução falha', function () {
        ($this->generateBudget)()->assertOk();
        sagaDeliver(MessageType::BudgetApproved, $this->osId);
        sagaDeliver(MessageType::PaymentConfirmed, $this->osId, ['payment_id' => 'mp-2']);
        sagaDeliver(MessageType::ExecutionStarted, $this->osId);

        expect($this->part->fresh()->stock_quantity)->toBe(3);

        sagaDeliver(MessageType::ExecutionFailed, $this->osId, ['reason' => 'elevador quebrado']);

        expect(osStatus($this->osId))->toBe('rejected')
            ->and(sagaState($this->osId))->toBe('compensating')
            ->and($this->part->fresh()->stock_quantity)->toBe(5)
            ->and(lastOutbox(MessageType::RefundPayment)['payload']['reason'])->toBe('execução falhou: elevador quebrado')
            ->and(outboxTypes($this->osId))->toContain('os.order.cancelled');
    });

    it('cancela orçamento e OS quando o pagamento é recusado', function () {
        ($this->generateBudget)()->assertOk();
        sagaDeliver(MessageType::BudgetApproved, $this->osId);
        sagaDeliver(MessageType::PaymentFailed, $this->osId, ['reason' => 'cartão recusado']);

        $saga = SagaInstanceModel::where('os_id', $this->osId)->first();
        expect(osStatus($this->osId))->toBe('rejected')
            ->and($saga->state)->toBe('compensated')
            ->and($saga->failure_reason)->toBe('pagamento recusado: cartão recusado')
            ->and(outboxTypes($this->osId))->toContain('billing.budget.cancel')
            ->and($this->part->fresh()->stock_quantity)->toBe(5);
    });

    it('volta ao diagnóstico na recusa com renegociação e cancela na recusa definitiva', function () {
        ($this->generateBudget)()->assertOk();
        sagaDeliver(MessageType::BudgetRejected, $this->osId, ['final' => false]);

        expect(osStatus($this->osId))->toBe('in_renegotiation')
            ->and(sagaState($this->osId))->toBe('diagnosis');

        // Nova proposta → revisão 2 enviada ao Billing.
        ($this->generateBudget)(1)->assertOk();
        expect(sagaState($this->osId))->toBe('awaiting_approval')
            ->and(lastOutbox(MessageType::GenerateBudget)['payload']['revision'])->toBe(2);

        sagaDeliver(MessageType::BudgetRejected, $this->osId, ['final' => true]);

        expect(osStatus($this->osId))->toBe('rejected')
            ->and(sagaState($this->osId))->toBe('cancelled');
    });

    it('aprova a partir da renegociação', function () {
        ($this->generateBudget)()->assertOk();
        sagaDeliver(MessageType::BudgetRejected, $this->osId);
        ($this->generateBudget)()->assertOk();

        // OS volta a pending_approval na nova proposta; força o caminho da renegociação.
        OrderServiceModel::whereKey($this->osId)->update(['status' => 'in_renegotiation']);
        sagaDeliver(MessageType::BudgetApproved, $this->osId);

        expect(osStatus($this->osId))->toBe('approved');
    });

    it('não gera orçamento novo enquanto aguarda aprovação', function () {
        ($this->generateBudget)()->assertOk();

        OrderServiceModel::whereKey($this->osId)->update(['status' => 'in_analysis']);
        ($this->generateBudget)()->assertStatus(422)
            ->assertJsonFragment(['message' => "A saga da OS #{$this->osId} está em 'awaiting_approval'; não é possível gerar orçamento agora."]);
    });

    it('trata a execução finalizada sem o evento de início', function () {
        ($this->generateBudget)()->assertOk();
        sagaDeliver(MessageType::BudgetApproved, $this->osId);
        sagaDeliver(MessageType::PaymentConfirmed, $this->osId);
        sagaDeliver(MessageType::ExecutionFinished, $this->osId);

        $os = OrderServiceModel::find($this->osId);
        expect($os->status)->toBe('execution_finished')
            ->and($os->started_at)->not->toBeNull()
            ->and(sagaState($this->osId))->toBe('completed');
    });

    it('processa cada mensagem uma única vez (inbox)', function () {
        ($this->generateBudget)()->assertOk();
        sagaDeliver(MessageType::BudgetApproved, $this->osId);
        $paid = sagaEvent(MessageType::PaymentConfirmed, $this->osId, ['payment_id' => 'mp-3']);

        app(OsSagaOrchestrator::class)->handle($paid);
        app(OsSagaOrchestrator::class)->handle($paid);

        expect($this->part->fresh()->stock_quantity)->toBe(3)
            ->and(OutboxMessageModel::where('type', 'execution.job.enqueue')->count())->toBe(1);
    });

    it('ignora mensagens fora de ordem, sem saga ou de tipo desconhecido', function () {
        sagaDeliver(MessageType::PaymentConfirmed, $this->osId);
        expect(sagaState($this->osId))->toBe('diagnosis');

        app(OsSagaOrchestrator::class)->handle(
            Envelope::new(MessageType::BudgetApproved, null, null, 999999, 'test-suite')
        );
        app(OsSagaOrchestrator::class)->handle(
            Envelope::new(MessageType::BudgetApproved, null, null, null, 'test-suite')
        );
        app(OsSagaOrchestrator::class)->handle(Envelope::fromArray([
            'id' => 'x-1', 'type' => 'billing.something.else', 'source' => 'test-suite', 'os_id' => $this->osId,
        ]));

        expect(sagaState($this->osId))->toBe('diagnosis')
            ->and(osStatus($this->osId))->toBe('in_analysis');
    });

    it('localiza a saga pelo saga_id quando a mensagem não traz os_id', function () {
        ($this->generateBudget)()->assertOk();
        $saga = SagaInstanceModel::where('os_id', $this->osId)->first();

        app(OsSagaOrchestrator::class)->handle(
            Envelope::new(MessageType::BudgetApproved, $saga->id, null, null, 'test-suite')
        );

        expect(osStatus($this->osId))->toBe('approved');
    });

    it('expõe estado e histórico da saga na API', function () {
        ($this->generateBudget)()->assertOk();

        $this->actingAs($this->attendant, 'sanctum')
            ->getJson("/api/order-services/{$this->osId}/saga")
            ->assertOk()
            ->assertJsonPath('data.state', 'awaiting_approval')
            ->assertJsonPath('data.correlation_id', 'req-saga-123')
            ->assertJsonPath('data.history.0.state', 'diagnosis')
            ->assertJsonPath('data.history.1.state', 'awaiting_approval');

        $this->actingAs($this->attendant, 'sanctum')
            ->getJson('/api/order-services/999999/saga')
            ->assertNotFound();
    });

    it('não cria saga com a saga desligada', function () {
        config(['messaging.saga_enabled' => false]);

        $id = $this->actingAs($this->attendant, 'sanctum')
            ->postJson('/api/order-services', [
                'customer_id' => $this->customer->id,
                'vehicle_id' => $this->vehicle->id,
                'complaint' => 'Sem saga',
            ])
            ->assertCreated()
            ->json('data.id');

        expect(SagaInstanceModel::where('os_id', $id)->exists())->toBeFalse();
    });
});
