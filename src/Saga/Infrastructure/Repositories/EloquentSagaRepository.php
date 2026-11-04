<?php

namespace Domain\Saga\Infrastructure\Repositories;

use Domain\Saga\Domain\Entities\OsSaga;
use Domain\Saga\Domain\Enums\SagaState;
use Domain\Saga\Domain\Repositories\SagaRepositoryInterface;
use Domain\Saga\Infrastructure\Models\SagaInstanceModel;

class EloquentSagaRepository implements SagaRepositoryInterface
{
    public function findById(string $id): ?OsSaga
    {
        $model = SagaInstanceModel::find($id);

        return $model ? $this->toEntity($model) : null;
    }

    public function findByOsId(int $osId, bool $lock = false): ?OsSaga
    {
        $query = SagaInstanceModel::where('os_id', $osId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $model = $query->first();

        return $model ? $this->toEntity($model) : null;
    }

    public function save(OsSaga $saga): void
    {
        SagaInstanceModel::updateOrCreate(
            ['id' => $saga->getId()],
            [
                'os_id' => $saga->getOsId(),
                'state' => $saga->getState()->value,
                'correlation_id' => $saga->getCorrelationId(),
                'context' => $saga->getContext(),
                'history' => $saga->getHistory(),
                'failure_reason' => $saga->getFailureReason(),
                'deadline_at' => $saga->getDeadlineAt(),
            ],
        );
    }

    public function findOverdue(\DateTimeImmutable $now): array
    {
        $terminal = array_map(
            fn (SagaState $s) => $s->value,
            array_filter(SagaState::cases(), fn (SagaState $s) => $s->isTerminal()),
        );

        return SagaInstanceModel::whereNotNull('deadline_at')
            ->where('deadline_at', '<', $now)
            ->whereNotIn('state', $terminal)
            ->orderBy('deadline_at')
            ->limit(100)
            ->get()
            ->map(fn (SagaInstanceModel $m) => $this->toEntity($m))
            ->all();
    }

    private function toEntity(SagaInstanceModel $model): OsSaga
    {
        return new OsSaga(
            id: $model->id,
            osId: (int) $model->os_id,
            state: SagaState::from($model->state),
            correlationId: $model->correlation_id,
            context: $model->context ?? [],
            history: $model->history ?? [],
            failureReason: $model->failure_reason,
            deadlineAt: $model->deadline_at,
        );
    }
}
