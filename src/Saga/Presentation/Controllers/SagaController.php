<?php

namespace Domain\Saga\Presentation\Controllers;

use App\Http\Controllers\Controller;
use Domain\Saga\Domain\Repositories\SagaRepositoryInterface;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Saga', description: 'Saga orquestrada da OS entre OS, Billing e Execution Service')]
class SagaController extends Controller
{
    public function __construct(private readonly SagaRepositoryInterface $sagas) {}

    #[OA\Get(
        path: '/api/order-services/{id}/saga',
        summary: 'Estado e histórico da saga da OS',
        security: [['sanctum' => []]],
        tags: ['Saga'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Estado atual (diagnosis, awaiting_approval, awaiting_payment, awaiting_execution, executing, completed, compensating, compensated, cancelled), contexto (orçamento, pagamento, peças reservadas), motivo da falha, prazo do passo atual e histórico de transições.'),
            new OA\Response(response: 404, description: 'OS sem saga (aberta com a saga desligada)'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $saga = $this->sagas->findByOsId($id);

        return $saga
            ? response()->json(['data' => $saga->toArray()])
            : response()->json(['message' => 'Saga não encontrada para esta OS.'], 404);
    }
}
