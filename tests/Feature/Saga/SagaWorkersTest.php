<?php

use App\Enums\UserRole;
use App\Models\User;
use Domain\Customer\Infrastructure\Models\CustomerModel;
use Domain\Customer\Infrastructure\Models\VehicleModel;
use Domain\Saga\Application\OsSagaOrchestrator;
use Domain\Saga\Domain\Messages\Envelope;
use Domain\Saga\Domain\Messages\MessageType;
use Domain\Saga\Domain\Ports\MessagePublisher;
use Domain\Saga\Domain\Ports\MessageSource;
use Domain\Saga\Domain\Ports\ReceivedMessage;
use Domain\Saga\Infrastructure\Models\OutboxMessageModel;
use Domain\Saga\Infrastructure\Models\SagaInstanceModel;
use Domain\Workshop\Infrastructure\Models\OrderServiceModel;
use Illuminate\Support\Facades\Mail;

/** Fila em memória: entrega as mensagens uma vez e registra os acks. */
class FakeMessageSource implements MessageSource
{
    /** @var string[] */
    public array $acked = [];

    /** @param ReceivedMessage[] $queue */
    public function __construct(public array $queue = []) {}

    public function receive(): array
    {
        $batch = $this->queue;
        $this->queue = [];

        return $batch;
    }

    public function ack(ReceivedMessage $message): void
    {
        $this->acked[] = $message->receipt;
    }
}

class FakeMessagePublisher implements MessagePublisher
{
    /** @var Envelope[] */
    public array $published = [];

    public function __construct(public ?string $failOnType = null) {}

    public function publish(Envelope $envelope): void
    {
        if ($envelope->type === $this->failOnType) {
            throw new RuntimeException('broker indisponível');
        }
        $this->published[] = $envelope;
    }
}

function workersOpenOs(object $test): int
{
    return $test->actingAs($test->attendant, 'sanctum')
        ->postJson('/api/order-services', [
            'customer_id' => $test->customer->id,
            'vehicle_id' => $test->vehicle->id,
            'complaint' => 'Motor falhando',
        ])
        ->assertCreated()
        ->json('data.id');
}

function workersMoveSaga(int $osId, string $state, string $osStatus, ?string $deadline): void
{
    SagaInstanceModel::where('os_id', $osId)->update(['state' => $state, 'deadline_at' => $deadline]);
    OrderServiceModel::whereKey($osId)->update(['status' => $osStatus]);
}

describe('Workers da saga', function () {

    beforeEach(function () {
        Mail::fake();
        config(['messaging.saga_enabled' => true]);

        $this->attendant = User::factory()->create(['role' => UserRole::Attendant]);
        $this->customer = CustomerModel::create([
            'name' => 'Cliente Workers',
            'document' => '52998224725',
            'email' => 'workers@test.com',
        ]);
        $this->vehicle = VehicleModel::create([
            'customer_id' => $this->customer->id,
            'plate' => 'WRK-0001',
            'brand' => 'VW',
            'model' => 'Gol',
            'year' => 2020,
            'color' => 'Preto',
        ]);
    });

    it('saga:consume processa, confirma e descarta malformadas, mantendo as que falham', function () {
        $osId = workersOpenOs($this);
        workersMoveSaga($osId, 'awaiting_approval', 'pending_approval', null);
        $saga = SagaInstanceModel::where('os_id', $osId)->first();

        $approved = Envelope::new(MessageType::BudgetApproved, $saga->id, null, $osId, 'oficina-billing-service');
        // Notificação SNS sem raw delivery: o envelope vem dentro de "Message".
        $snsWrapped = json_encode(['Type' => 'Notification', 'Message' => $approved->toJson()]);
        // Saga aguardando aprovação, mas OS ainda "created": a transição é
        // inválida, o handler lança e a mensagem não é confirmada.
        $brokenOs = workersOpenOs($this);
        workersMoveSaga($brokenOs, 'awaiting_approval', 'created', null);

        $source = new FakeMessageSource([
            new ReceivedMessage($snsWrapped, 'r-ok'),
            new ReceivedMessage('{nao-e-json', 'r-malformed'),
            new ReceivedMessage(Envelope::new(MessageType::BudgetApproved, null, null, $brokenOs, 'x')->toJson(), 'r-fail'),
        ]);
        app()->instance(MessageSource::class, $source);

        $this->artisan('saga:consume', ['--once' => true])
            ->expectsOutput('processadas: 1 | com falha: 1')
            ->assertSuccessful();

        expect($source->acked)->toBe(['r-ok', 'r-malformed'])
            ->and(OrderServiceModel::find($osId)->status)->toBe('approved');
    });

    it('saga:relay publica o outbox em ordem e para no primeiro erro', function () {
        $osId = workersOpenOs($this);
        workersMoveSaga($osId, 'awaiting_approval', 'pending_approval', null);
        app(OsSagaOrchestrator::class)->handle(
            Envelope::new(MessageType::BudgetApproved, null, null, $osId, 'oficina-billing-service')
        );

        // Outbox: os.order.opened, billing.payment.request
        $publisher = new FakeMessagePublisher(failOnType: 'billing.payment.request');
        app()->instance(MessagePublisher::class, $publisher);

        $this->artisan('saga:relay', ['--once' => true])
            ->expectsOutput('publicadas: 1')
            ->assertSuccessful();

        $failed = OutboxMessageModel::where('type', 'billing.payment.request')->first();
        expect(array_map(fn ($e) => $e->type, $publisher->published))->toBe(['os.order.opened'])
            ->and($failed->published_at)->toBeNull()
            ->and($failed->attempts)->toBe(1)
            ->and($failed->last_error)->toBe('broker indisponível');

        app()->instance(MessagePublisher::class, new FakeMessagePublisher);
        $this->artisan('saga:relay', ['--once' => true])->assertSuccessful();

        expect(OutboxMessageModel::whereNull('published_at')->count())->toBe(0)
            ->and($failed->fresh()->last_error)->toBeNull();
    });

    it('saga:timeouts compensa pagamento e execução vencidos', function () {
        $waitingPayment = workersOpenOs($this);
        workersMoveSaga($waitingPayment, 'awaiting_payment', 'approved', now()->subMinute()->toDateTimeString());

        $waitingExecution = workersOpenOs($this);
        workersMoveSaga($waitingExecution, 'awaiting_execution', 'approved', now()->subMinute()->toDateTimeString());
        SagaInstanceModel::where('os_id', $waitingExecution)->update(['context' => ['payment_id' => 'mp-7']]);

        $notYet = workersOpenOs($this);
        workersMoveSaga($notYet, 'awaiting_payment', 'approved', now()->addHour()->toDateTimeString());

        $this->artisan('saga:timeouts')
            ->expectsOutput('sagas expiradas: 2')
            ->assertSuccessful();

        expect(SagaInstanceModel::where('os_id', $waitingPayment)->value('state'))->toBe('compensated')
            ->and(SagaInstanceModel::where('os_id', $waitingExecution)->value('state'))->toBe('compensating')
            ->and(SagaInstanceModel::where('os_id', $notYet)->value('state'))->toBe('awaiting_payment')
            ->and(OrderServiceModel::find($waitingPayment)->status)->toBe('rejected')
            ->and(OutboxMessageModel::where('type', 'billing.payment.cancel')->count())->toBe(1)
            ->and(OutboxMessageModel::where('type', 'execution.job.cancel')->count())->toBe(1)
            ->and(OutboxMessageModel::where('type', 'billing.payment.refund')->first()->body['payload']['payment_id'])->toBe('mp-7');
    });

    it('expõe as métricas da saga e do outbox', function () {
        $osId = workersOpenOs($this);

        $this->get('/metrics')
            ->assertOk()
            ->assertSee('oficina_sagas{state="diagnosis"} 1', false)
            ->assertSee('oficina_outbox_pending_messages 1', false);

        expect($osId)->toBeInt();
    });
});
