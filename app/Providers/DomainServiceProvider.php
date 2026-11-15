<?php

namespace App\Providers;

use Aws\Sns\SnsClient;
use Aws\Sqs\SqsClient;
use Domain\Catalog\Domain\Repositories\PartRepositoryInterface;
use Domain\Catalog\Domain\Repositories\ServiceRepositoryInterface;
use Domain\Catalog\Infrastructure\Repositories\EloquentPartRepository;
use Domain\Catalog\Infrastructure\Repositories\EloquentServiceRepository;
use Domain\Customer\Domain\Repositories\CustomerRepositoryInterface;
use Domain\Customer\Domain\Repositories\VehicleRepositoryInterface;
use Domain\Customer\Infrastructure\Repositories\EloquentCustomerRepository;
use Domain\Customer\Infrastructure\Repositories\EloquentVehicleRepository;
use Domain\Inventory\Application\Policies\AddPartsToInventoryPolicy;
use Domain\Inventory\Application\Policies\NotifyMechanicPolicy;
use Domain\Inventory\Application\Policies\NotifyPurchasingPolicy;
use Domain\Inventory\Domain\Events\PartOutOfStockEvent;
use Domain\Inventory\Domain\Events\PartsAddedToInventoryEvent;
use Domain\Inventory\Domain\Events\PartsReceivedFromSupplierEvent;
use Domain\Inventory\Domain\Repositories\PartRequestRepositoryInterface;
use Domain\Inventory\Infrastructure\Repositories\EloquentPartRequestRepository;
use Domain\Saga\Domain\Ports\MessagePublisher;
use Domain\Saga\Domain\Ports\MessageSource;
use Domain\Saga\Domain\Repositories\InboxInterface;
use Domain\Saga\Domain\Repositories\OutboxInterface;
use Domain\Saga\Domain\Repositories\SagaRepositoryInterface;
use Domain\Saga\Infrastructure\Messaging\LogMessagePublisher;
use Domain\Saga\Infrastructure\Messaging\NullMessageSource;
use Domain\Saga\Infrastructure\Messaging\SnsMessagePublisher;
use Domain\Saga\Infrastructure\Messaging\SqsMessageSource;
use Domain\Saga\Infrastructure\Repositories\DatabaseInbox;
use Domain\Saga\Infrastructure\Repositories\EloquentOutbox;
use Domain\Saga\Infrastructure\Repositories\EloquentSagaRepository;
use Domain\Saga\Presentation\Commands\ConsumeMessagesCommand;
use Domain\Saga\Presentation\Commands\ExpireSagasCommand;
use Domain\Saga\Presentation\Commands\RelayOutboxCommand;
use Domain\Workshop\Application\Policies\NotifyCustomerOfOsStatusPolicy;
use Domain\Workshop\Domain\Events\OsStatusUpdatedEvent;
use Domain\Workshop\Domain\Repositories\OrderServiceRepositoryInterface;
use Domain\Workshop\Infrastructure\Repositories\EloquentOrderServiceRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Customer bounded context
        $this->app->bind(CustomerRepositoryInterface::class, EloquentCustomerRepository::class);
        $this->app->bind(VehicleRepositoryInterface::class, EloquentVehicleRepository::class);

        // Catalog bounded context
        $this->app->bind(PartRepositoryInterface::class, EloquentPartRepository::class);
        $this->app->bind(ServiceRepositoryInterface::class, EloquentServiceRepository::class);

        // Inventory bounded context
        $this->app->bind(PartRequestRepositoryInterface::class, EloquentPartRequestRepository::class);

        // Workshop bounded context
        $this->app->bind(OrderServiceRepositoryInterface::class, EloquentOrderServiceRepository::class);

        // Saga (orquestração entre microsserviços)
        $this->app->bind(SagaRepositoryInterface::class, EloquentSagaRepository::class);
        $this->app->bind(OutboxInterface::class, EloquentOutbox::class);
        $this->app->bind(InboxInterface::class, DatabaseInbox::class);

        // Credenciais AWS pela cadeia padrão do SDK (IRSA no EKS).
        $this->app->bind(MessagePublisher::class, fn () => match (config('messaging.publisher')) {
            'sns' => new SnsMessagePublisher(
                new SnsClient(['region' => config('messaging.region'), 'version' => '2010-03-31']),
                (string) config('messaging.sns.topic_arn'),
            ),
            default => new LogMessagePublisher,
        });

        $this->app->bind(MessageSource::class, fn () => match (config('messaging.consumer')) {
            'sqs' => new SqsMessageSource(
                new SqsClient(['region' => config('messaging.region'), 'version' => '2012-11-05']),
                (string) config('messaging.sqs.queue_url'),
                (int) config('messaging.sqs.wait_seconds'),
                (int) config('messaging.sqs.max_messages'),
            ),
            default => new NullMessageSource,
        });
    }

    public function boot(): void
    {
        // ── Inventory Domain Events → Policies ───────────────────────────
        // POL: Notifica Compras quando estoque insuficiente
        Event::listen(PartOutOfStockEvent::class, NotifyPurchasingPolicy::class);

        // POL: Adiciona peças ao estoque após recebimento do fornecedor
        Event::listen(PartsReceivedFromSupplierEvent::class, AddPartsToInventoryPolicy::class);

        // POL: Notifica Mecânico após estoque atualizado
        Event::listen(PartsAddedToInventoryEvent::class, NotifyMechanicPolicy::class);

        // ── Workshop Domain Events → Policies ────────────────────────────
        // POL: Notifica Cliente por e-mail a cada mudança de status da OS
        Event::listen(OsStatusUpdatedEvent::class, NotifyCustomerOfOsStatusPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ConsumeMessagesCommand::class,
                RelayOutboxCommand::class,
                ExpireSagasCommand::class,
            ]);
        }
    }
}
