<?php

use Domain\Customer\Infrastructure\Models\CustomerModel;
use Domain\Customer\Infrastructure\Models\VehicleModel;
use Domain\Workshop\Domain\Enums\OsStatus;
use Domain\Workshop\Infrastructure\Models\OrderServiceModel;

describe('Metrics endpoint', function () {

    it('publishes every OS status and zeroes a status that no longer has orders', function () {
        $customer = CustomerModel::create([
            'name' => 'Cliente Métricas',
            'document' => '52998224725',
            'email' => 'metricas@test.com',
        ]);

        $vehicle = VehicleModel::create([
            'customer_id' => $customer->id,
            'plate' => 'ABC1D23',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2020,
            'color' => 'Prata',
        ]);

        $os = OrderServiceModel::create([
            'status' => OsStatus::InExecution->value,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'complaint' => 'Barulho na suspensão',
        ]);

        expect($this->get('/metrics')->assertOk()->getContent())
            ->toMatch('/oficina_order_services\{status="in_execution"\} 1(\.0+)?\b/');

        // A OS sai de in_execution: o registry é o mesmo entre scrapes (no
        // Redis em produção), então o status antigo precisa voltar a zero.
        $os->update(['status' => OsStatus::ExecutionFinished->value]);

        $body = $this->get('/metrics')->assertOk()->getContent();

        expect($body)
            ->toMatch('/oficina_order_services\{status="in_execution"\} 0(\.0+)?\b/')
            ->toMatch('/oficina_order_services\{status="execution_finished"\} 1(\.0+)?\b/');

        foreach (OsStatus::cases() as $status) {
            expect($body)->toContain('oficina_order_services{status="'.$status->value.'"}');
        }
    });
});
