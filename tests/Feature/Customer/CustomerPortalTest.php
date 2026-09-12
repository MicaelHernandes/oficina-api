<?php

use Domain\Customer\Infrastructure\Models\CustomerModel;
use Domain\Customer\Infrastructure\Models\VehicleModel;
use Domain\Workshop\Domain\Enums\OsStatus;
use Domain\Workshop\Infrastructure\Models\OrderServiceModel;
use Firebase\JWT\JWT;

describe('Customer Portal (JWT por CPF)', function () {

    beforeEach(function () {
        config([
            'gateway.jwt.enforce' => true,
            'gateway.jwt.secret' => 'segredo-de-teste',
            'gateway.jwt.issuer' => 'oficina-auth',
        ]);

        $this->customer = CustomerModel::create([
            'name' => 'Cliente Teste',
            'document' => '52998224725',
            'email' => 'cliente.teste@test.com',
        ]);
    });

    $token = function (string $sub, string $issuer = 'oficina-auth', string $secret = 'segredo-de-teste'): string {
        return JWT::encode([
            'sub' => $sub,
            'cpf' => '52998224725',
            'iss' => $issuer,
            'iat' => time(),
            'exp' => time() + 900,
        ], $secret, 'HS256');
    };

    it('returns the authenticated customer', function () use ($token) {
        $this->withHeader('Authorization', 'Bearer '.$token((string) $this->customer->id))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->customer->id)
            ->assertJsonPath('data.document', '52998224725');
    });

    it('rejects requests without a token', function () {
        $this->getJson('/api/me')->assertUnauthorized();
    });

    it('rejects a token signed with another secret', function () use ($token) {
        $this->withHeader('Authorization', 'Bearer '.$token((string) $this->customer->id, 'oficina-auth', 'outro-segredo'))
            ->getJson('/api/me')
            ->assertUnauthorized();
    });

    it('rejects a token from another issuer', function () use ($token) {
        $this->withHeader('Authorization', 'Bearer '.$token((string) $this->customer->id, 'outro-emissor'))
            ->getJson('/api/me')
            ->assertUnauthorized();
    });

    it('lists only the vehicles of the authenticated customer', function () use ($token) {
        $other = CustomerModel::create([
            'name' => 'Outro Cliente',
            'document' => '87748024537',
            'email' => 'outro@test.com',
        ]);

        VehicleModel::create([
            'customer_id' => $this->customer->id,
            'plate' => 'ABC1D23',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2020,
            'color' => 'Prata',
        ]);
        VehicleModel::create([
            'customer_id' => $other->id,
            'plate' => 'XYZ9876',
            'brand' => 'Honda',
            'model' => 'Civic',
            'year' => 2018,
            'color' => 'Preto',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token((string) $this->customer->id))
            ->getJson('/api/me/vehicles')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.plate', 'ABC1D23');
    });

    it('lists only the order services of the authenticated customer', function () use ($token) {
        $other = CustomerModel::create([
            'name' => 'Outro Cliente',
            'document' => '87748024537',
            'email' => 'outro2@test.com',
        ]);

        $vehicle = VehicleModel::create([
            'customer_id' => $this->customer->id,
            'plate' => 'ABC1D23',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2020,
            'color' => 'Prata',
        ]);

        $mine = OrderServiceModel::create([
            'status' => OsStatus::Created->value,
            'customer_id' => $this->customer->id,
            'vehicle_id' => $vehicle->id,
            'complaint' => 'Barulho na suspensão',
        ]);
        $theirs = OrderServiceModel::create([
            'status' => OsStatus::Created->value,
            'customer_id' => $other->id,
            'vehicle_id' => $vehicle->id,
            'complaint' => 'Troca de óleo',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token((string) $this->customer->id))
            ->getJson('/api/me/order-services')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->withHeader('Authorization', 'Bearer '.$token((string) $this->customer->id))
            ->getJson("/api/me/order-services/{$theirs->id}")
            ->assertNotFound();
    });
});
