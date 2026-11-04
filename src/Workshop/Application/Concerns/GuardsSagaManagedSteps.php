<?php

namespace Domain\Workshop\Application\Concerns;

use Domain\Core\Domain\Exceptions\DomainException;

/**
 * Com a saga ligada, aprovação/recusa do orçamento e início/fim da execução
 * chegam como eventos do Billing e do Execution Service. As rotas locais
 * dessas etapas ficam bloqueadas para não divergir do estado da saga.
 */
trait GuardsSagaManagedSteps
{
    private function guardNotManagedBySaga(string $step, string $service): void
    {
        if (config('messaging.saga_enabled')) {
            throw DomainException::because(
                "Com a saga ligada, {$step} fica a cargo do {$service}."
            );
        }
    }
}
