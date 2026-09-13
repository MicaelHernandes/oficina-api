<?php

namespace Domain\Customer\Presentation\Docs;

use OpenApi\Attributes as OA;

/**
 * Documentação do login por CPF. A rota não existe nesta aplicação: é servida
 * pela Lambda de auth (repo oficina-auth-lambda) atrás do API Gateway, por
 * isso aponta para api.codefive.com.br. O Swagger fica junto das rotas
 * /api/me, que consomem o token emitido aqui.
 */
#[OA\PathItem(
    path: '/auth',
    servers: [new OA\Server(url: 'https://api.codefive.com.br', description: 'API Gateway (Lambda de auth)')],
    post: new OA\Post(
        summary: 'Login do cliente por CPF (emite o JWT do portal)',
        description: 'Valida os dígitos do CPF, consulta o cliente no banco e emite um JWT HS256 válido por 15 minutos. Use o token no botão Authorize, campo cpfJwt, para chamar as rotas /api/me.',
        tags: ['CustomerPortal'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['cpf'],
                properties: [
                    new OA\Property(property: 'cpf', type: 'string', description: 'CPF com ou sem máscara', example: '529.982.247-25'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Cliente ativo: token emitido',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'token', type: 'string', example: 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...'),
                        new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                        new OA\Property(property: 'expires_in', type: 'integer', example: 900),
                        new OA\Property(
                            property: 'customer',
                            properties: [
                                new OA\Property(property: 'id', type: 'string', example: '1'),
                                new OA\Property(property: 'name', type: 'string', example: 'Cliente Teste'),
                            ],
                            type: 'object'
                        ),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'CPF com dígitos inválidos',
                content: new OA\JsonContent(example: ['error' => 'invalid_cpf', 'message' => 'CPF inválido.'])
            ),
            new OA\Response(
                response: 401,
                description: 'Cliente inexistente ou inativo',
                content: new OA\JsonContent(example: ['error' => 'customer_not_found', 'message' => 'Cliente inexistente ou inativo.'])
            ),
        ]
    )
)]
final class CustomerAuthDoc {}
