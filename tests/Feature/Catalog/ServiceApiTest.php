<?php

use App\Enums\UserRole;
use App\Models\User;
use Domain\Catalog\Infrastructure\Models\ServiceModel;

describe('Service API (Catalog)', function () {

    beforeEach(function () {
        $this->user = User::factory()->create(['role' => UserRole::Attendant]);
    });

    it('lists services', function () {
        ServiceModel::create(['name' => 'Alinhamento', 'base_price' => 120.00, 'estimated_minutes' => 40, 'is_active' => true]);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Alinhamento');
    });

    it('creates, shows, updates and deletes a service', function () {
        $id = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/services', [
                'name' => 'Balanceamento',
                'base_price' => 90.5,
                'description' => 'Quatro rodas',
                'estimated_minutes' => 30,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Balanceamento')
            ->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/services/{$id}")
            ->assertOk()
            ->assertJsonPath('data.description', 'Quatro rodas');

        $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/services/{$id}", [
                'name' => 'Balanceamento completo',
                'base_price' => 110,
                'estimated_minutes' => 45,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Balanceamento completo');

        $this->assertDatabaseHas('services', ['id' => $id, 'name' => 'Balanceamento completo']);

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/services/{$id}")
            ->assertNoContent();
    });

    it('validates required fields', function () {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/services', ['base_price' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'base_price']);
    });

    it('returns 404 for unknown service', function () {
        $this->actingAs($this->user, 'sanctum')->getJson('/api/services/999999')->assertNotFound();
        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/services/999999', ['name' => 'X', 'base_price' => 1])
            ->assertNotFound();
        $this->actingAs($this->user, 'sanctum')->deleteJson('/api/services/999999')->assertNotFound();
    });
});
