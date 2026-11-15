<?php

namespace Domain\Saga\Domain\Repositories;

use Domain\Saga\Domain\Entities\OsSaga;

interface SagaRepositoryInterface
{
    public function findById(string $id): ?OsSaga;

    /** Com `$lock`, trava a linha até o fim da transação (SELECT ... FOR UPDATE). */
    public function findByOsId(int $osId, bool $lock = false): ?OsSaga;

    public function save(OsSaga $saga): void;

    /** @return OsSaga[] sagas não terminadas cujo prazo do passo atual venceu */
    public function findOverdue(\DateTimeImmutable $now): array;
}
