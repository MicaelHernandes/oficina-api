<?php

namespace Domain\Customer\Presentation\Controllers;

use App\Http\Controllers\Controller;
use Domain\Customer\Infrastructure\Models\CustomerModel;
use Domain\Customer\Infrastructure\Models\VehicleModel;
use Domain\Workshop\Domain\Enums\OsStatus;
use Domain\Workshop\Infrastructure\Models\OrderServiceModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Portal do cliente: rotas autenticadas pelo JWT de CPF emitido pela Lambda
 * (repo 1) e validado pelo middleware `gateway.jwt`. O cliente só enxerga os
 * próprios dados — o id vem do claim `sub` do token, nunca da URL.
 */
#[OA\Tag(name: 'CustomerPortal', description: 'Portal do cliente (JWT por CPF, via API Gateway)')]
class CustomerPortalController extends Controller
{
    #[OA\Get(
        path: '/api/me',
        summary: 'Dados do cliente autenticado pelo CPF',
        tags: ['CustomerPortal'],
        responses: [
            new OA\Response(response: 200, description: 'Dados do cliente'),
            new OA\Response(response: 401, description: 'Token ausente ou inválido'),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);

        if ($customer === null) {
            return $this->unauthenticated();
        }

        return response()->json([
            'data' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'document' => $customer->document,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/me/vehicles',
        summary: 'Veículos do cliente autenticado',
        tags: ['CustomerPortal'],
        responses: [
            new OA\Response(response: 200, description: 'Lista de veículos'),
            new OA\Response(response: 401, description: 'Token ausente ou inválido'),
        ]
    )]
    public function vehicles(Request $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);

        if ($customer === null) {
            return $this->unauthenticated();
        }

        $vehicles = VehicleModel::where('customer_id', $customer->id)
            ->orderBy('id')
            ->get()
            ->map(fn (VehicleModel $vehicle): array => [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
                'year' => $vehicle->year,
                'color' => $vehicle->color,
            ])
            ->all();

        return response()->json(['data' => $vehicles]);
    }

    #[OA\Get(
        path: '/api/me/order-services',
        summary: 'Ordens de serviço do cliente autenticado',
        tags: ['CustomerPortal'],
        responses: [
            new OA\Response(response: 200, description: 'Lista de OS do cliente'),
            new OA\Response(response: 401, description: 'Token ausente ou inválido'),
        ]
    )]
    public function orderServices(Request $request): JsonResponse
    {
        $customer = $this->currentCustomer($request);

        if ($customer === null) {
            return $this->unauthenticated();
        }

        $orders = OrderServiceModel::where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (OrderServiceModel $os): array => $this->summary($os))
            ->all();

        return response()->json(['data' => $orders]);
    }

    #[OA\Get(
        path: '/api/me/order-services/{id}',
        summary: 'Detalhe de uma OS do cliente autenticado',
        tags: ['CustomerPortal'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Detalhe da OS'),
            new OA\Response(response: 401, description: 'Token ausente ou inválido'),
            new OA\Response(response: 404, description: 'OS inexistente ou de outro cliente'),
        ]
    )]
    public function orderService(Request $request, int $id): JsonResponse
    {
        $customer = $this->currentCustomer($request);

        if ($customer === null) {
            return $this->unauthenticated();
        }

        $os = OrderServiceModel::where('customer_id', $customer->id)->find($id);

        if ($os === null) {
            return response()->json(['message' => 'Ordem de Serviço não encontrada.'], 404);
        }

        $status = OsStatus::from((string) $os->status);

        return response()->json([
            'data' => $this->summary($os) + [
                'message' => $status->customerNotificationMessage(),
            ],
        ]);
    }

    /**
     * Cliente do token (`sub`), resolvido pelo middleware `gateway.jwt`.
     */
    private function currentCustomer(Request $request): ?CustomerModel
    {
        $id = $request->attributes->get('gateway_customer_id');

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        return CustomerModel::find((int) $id);
    }

    private function unauthenticated(): JsonResponse
    {
        return response()->json([
            'error' => 'unauthorized',
            'message' => 'Autentique-se pelo CPF no API Gateway.',
        ], 401);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(OrderServiceModel $os): array
    {
        $status = OsStatus::from((string) $os->status);
        $vehicle = VehicleModel::find($os->vehicle_id);

        return [
            'id' => $os->id,
            'status' => $status->value,
            'status_label' => $status->label(),
            'public_status' => [
                'value' => $status->toPublicStatus()->value,
                'label' => $status->toPublicStatus()->label(),
            ],
            'complaint' => $os->complaint,
            'vehicle' => $vehicle === null ? null : [
                'id' => $vehicle->id,
                'plate' => $vehicle->plate,
                'brand' => $vehicle->brand,
                'model' => $vehicle->model,
            ],
            'started_at' => $os->started_at?->toIso8601String(),
            'finished_at' => $os->finished_at?->toIso8601String(),
            'created_at' => $os->created_at?->toIso8601String(),
        ];
    }
}
