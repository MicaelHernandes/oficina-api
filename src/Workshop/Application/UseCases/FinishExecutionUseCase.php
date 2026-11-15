<?php

namespace Domain\Workshop\Application\UseCases;

use Domain\Core\Domain\Exceptions\NotFoundException;
use Domain\Customer\Domain\Repositories\CustomerRepositoryInterface;
use Domain\Workshop\Application\Concerns\DispatchesOsEvents;
use Domain\Workshop\Application\Concerns\GuardsSagaManagedSteps;
use Domain\Workshop\Domain\Entities\OrderService;
use Domain\Workshop\Domain\Repositories\OrderServiceRepositoryInterface;

/**
 * AG: Execução da OS
 * Mecânico conclui a execução → IN_EXECUTION → EXECUTION_FINISHED
 */
class FinishExecutionUseCase
{
    use DispatchesOsEvents, GuardsSagaManagedSteps;

    public function __construct(
        private readonly OrderServiceRepositoryInterface $osRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
    ) {}

    public function execute(int $osId): OrderService
    {
        $this->guardNotManagedBySaga('o fim da execução', 'Execution Service');

        $os = $this->osRepository->findById($osId)
            ?? throw NotFoundException::forEntity('Ordem de Serviço', $osId);

        $previous = $os->getStatus();
        $os->finishExecution();
        $os->markFinishedAt(new \DateTimeImmutable);

        $saved = $this->osRepository->save($os);
        $this->dispatchOsStatusUpdated($saved, $previous, $this->customerRepository);

        return $saved;
    }
}
