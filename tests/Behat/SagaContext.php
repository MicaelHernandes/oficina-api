<?php

namespace Tests\Behat;

use App\Enums\UserRole;
use App\Models\User;
use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Hook\BeforeScenario;
use Behat\Hook\BeforeSuite;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
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
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * Passos da saga da OS (features/saga_ordem_de_servico.feature).
 *
 * O OS Service roda de verdade: HTTP pelas rotas da API, orquestrador,
 * outbox e PostgreSQL. O Billing e o Execution são representados pelos
 * eventos que publicariam no SNS (entregues ao orquestrador como faria o
 * `saga:consume`) e pelos comandos que receberiam, lidos do outbox.
 */
class SagaContext implements Context
{
    use InteractsWithAuthentication;
    use MakesHttpRequests;

    /** Ambiente dos cenários (o Behat não lê o phpunit.xml). */
    private const ENV = [
        'APP_ENV' => 'testing',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'METRICS_STORAGE' => 'memory',
        'GATEWAY_JWT_ENFORCE' => 'false',
        'MESSAGING_PUBLISHER' => 'log',
        'MESSAGING_CONSUMER' => 'null',
        'BCRYPT_ROUNDS' => '4',
    ];

    protected Application $app;

    private User $attendant;

    private CustomerModel $customer;

    private VehicleModel $vehicle;

    /** @var array<string, int> nome → id no catálogo */
    private array $catalog = [];

    private int $osId;

    private string $correlationId;

    #[BeforeSuite]
    public static function prepareDatabase(): void
    {
        $app = self::createApplication();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $app->flush();
    }

    #[BeforeScenario]
    public function bootApplication(): void
    {
        $this->app = self::createApplication();
        // Cada cenário numa transação revertida no fim: banco limpo sem migrar de novo.
        DB::beginTransaction();
        Mail::fake();
    }

    #[AfterScenario]
    public function rollback(): void
    {
        DB::rollBack();
        $this->app->flush();
    }

    private static function createApplication(): Application
    {
        foreach (self::ENV as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    // ── Dado ──────────────────────────────────────────────────────────

    #[Given('/^que a saga está ligada$/')]
    public function sagaEnabled(): void
    {
        config(['messaging.saga_enabled' => true]);
        $this->attendant = User::factory()->create(['role' => UserRole::Attendant]);
    }

    #[Given('/^o cliente "([^"]+)" com o veículo "([^"]+)" está cadastrado$/')]
    public function customerWithVehicle(string $name, string $plate): void
    {
        $this->customer = CustomerModel::create([
            'name' => $name,
            'document' => '52998224725',
            'email' => Str::slug($name, '.').'@cliente.test',
        ]);

        $this->vehicle = VehicleModel::create([
            'customer_id' => $this->customer->id,
            'plate' => $plate,
            'brand' => 'Fiat',
            'model' => 'Argo',
            'year' => 2023,
            'color' => 'Branco',
        ]);
    }

    #[Given('/^o catálogo tem o serviço "([^"]+)" por R\$ ([\d.,]+)$/')]
    public function catalogService(string $name, string $price): void
    {
        $this->catalog[$name] = ServiceModel::create([
            'name' => $name,
            'base_price' => $this->money($price),
            'estimated_minutes' => 60,
            'is_active' => true,
        ])->id;
    }

    #[Given('/^o catálogo tem a peça "([^"]+)" por R\$ ([\d.,]+) com (\d+) unidades em estoque$/')]
    public function catalogPart(string $name, string $price, int $stock): void
    {
        $this->catalog[$name] = PartModel::create([
            'code' => Str::upper(Str::slug($name)),
            'name' => $name,
            'unit_price' => $this->money($price),
            'stock_quantity' => $stock,
            'minimum_stock' => 1,
            'unit' => 'un',
        ])->id;
    }

    #[Given('/^outra OS consome o estoque de "([^"]+)", restando (\d+) unidades?$/')]
    public function stockConsumedElsewhere(string $part, int $remaining): void
    {
        PartModel::whereKey($this->catalog[$part])->update(['stock_quantity' => $remaining]);
    }

    // ── Ações do OS Service (HTTP) ────────────────────────────────────

    #[Given('/^o atendente abre uma OS com a reclamação "([^"]+)"$/')]
    public function openOrder(string $complaint): void
    {
        $this->correlationId = 'bdd-'.Str::uuid();

        $this->osId = $this->actingAs($this->attendant, 'sanctum')
            ->withHeader('X-Request-Id', $this->correlationId)
            ->postJson('/api/order-services', [
                'customer_id' => $this->customer->id,
                'vehicle_id' => $this->vehicle->id,
                'complaint' => $complaint,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    #[Given('/^o mecânico envia a OS para diagnóstico$/')]
    public function sendToAnalysis(): void
    {
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/order-services/{$this->osId}/send-to-analysis")
            ->assertOk();
    }

    #[Given('/^o mecânico gera o orçamento com (\d+) "([^"]+)" e (\d+) "([^"]+)"$/')]
    public function generateBudget(int $serviceQty, string $service, int $partQty, string $part): void
    {
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/order-services/{$this->osId}/generate-budget", [
                'services' => [['service_id' => $this->catalog[$service], 'quantity' => $serviceQty]],
                'parts' => [['part_id' => $this->catalog[$part], 'quantity' => $partQty]],
            ])
            ->assertOk();
    }

    #[When('/^o atendente entrega o veículo$/')]
    public function deliver(): void
    {
        $this->actingAs($this->attendant, 'sanctum')
            ->postJson("/api/order-services/{$this->osId}/deliver")
            ->assertOk();
    }

    // ── Eventos do Billing e do Execution ─────────────────────────────

    #[When('/^(?:que )?o (Billing|Execution) publica "([^"]+)"$/')]
    public function servicePublishes(string $service, string $type, array $extra = []): void
    {
        $saga = SagaInstanceModel::where('os_id', $this->osId)->firstOrFail();

        $payload = $extra + match ($type) {
            'billing.budget.generated', 'billing.budget.approved' => ['budget_id' => 'bdg-bdd'],
            'billing.payment.confirmed' => ['payment_id' => 'pay-bdd', 'amount' => 300.0],
            'billing.payment.refunded' => ['payment_id' => 'pay-bdd', 'refunded' => true],
            default => [],
        };

        app(OsSagaOrchestrator::class)->handle(Envelope::new(
            type: MessageType::from($type),
            sagaId: $saga->id,
            correlationId: $saga->correlation_id,
            osId: $this->osId,
            source: 'oficina-'.strtolower($service).'-service',
            payload: $payload,
        ));
    }

    #[When('/^(?:que )?o (Billing|Execution) publica "([^"]+)" com o motivo "([^"]+)"$/')]
    public function servicePublishesWithReason(string $service, string $type, string $reason): void
    {
        $this->servicePublishes($service, $type, ['reason' => $reason]);
    }

    #[When('/^(?:que )?o Billing publica "([^"]+)" de forma definitiva$/')]
    public function billingRejectsForGood(string $type): void
    {
        $this->servicePublishes('Billing', $type, ['final' => true]);
    }

    // ── Então ─────────────────────────────────────────────────────────

    #[Then('/^a OS deve estar "([^"]+)"$/')]
    public function orderStatusIs(string $status): void
    {
        Assert::assertSame($status, OrderServiceModel::whereKey($this->osId)->value('status'));
    }

    #[Then('/^a saga deve estar em "([^"]+)"$/')]
    public function sagaStateIs(string $state): void
    {
        Assert::assertSame($state, SagaInstanceModel::where('os_id', $this->osId)->value('state'));
    }

    #[Then('/^o motivo da falha da saga deve conter "([^"]+)"$/')]
    public function sagaFailureContains(string $text): void
    {
        Assert::assertStringContainsString($text, (string) SagaInstanceModel::where('os_id', $this->osId)->value('failure_reason'));
    }

    #[Then('/^o (?:Billing|Execution) deve receber o comando "([^"]+)"$/')]
    public function commandWasSent(string $type): array
    {
        $message = OutboxMessageModel::where('type', $type)->orderByDesc('id')->get()
            ->first(fn (OutboxMessageModel $m) => ($m->body['os_id'] ?? null) === $this->osId);

        Assert::assertNotNull($message, "Nenhum comando {$type} no outbox da OS #{$this->osId}.");

        return $message->body;
    }

    #[Then('/^o Billing deve receber o comando "([^"]+)" com total de R\$ ([\d.,]+)$/')]
    public function commandWithTotal(string $type, string $total): void
    {
        $payload = $this->commandWasSent($type)['payload'];

        Assert::assertEqualsWithDelta($this->money($total), (float) ($payload['total_amount'] ?? $payload['amount']), 0.001);
    }

    #[Then('/^o estoque de "([^"]+)" deve ser (\d+)$/')]
    public function stockIs(string $part, int $expected): void
    {
        Assert::assertSame($expected, (int) PartModel::whereKey($this->catalog[$part])->value('stock_quantity'));
    }

    #[Then('/^todas as mensagens da saga devem carregar o mesmo correlation id$/')]
    public function sameCorrelationId(): void
    {
        $ids = OutboxMessageModel::all()
            ->filter(fn (OutboxMessageModel $m) => ($m->body['os_id'] ?? null) === $this->osId)
            ->map(fn (OutboxMessageModel $m) => $m->body['correlation_id'])
            ->unique()
            ->values()
            ->all();

        Assert::assertSame([$this->correlationId], $ids);
    }

    private function money(string $value): float
    {
        return (float) str_replace(',', '.', str_replace('.', '', $value));
    }
}
