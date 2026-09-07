<?php

// Validação, no Laravel, do JWT emitido pela Lambda de auth (repo 1).
// Defense-in-depth: o API Gateway já valida via Lambda Authorizer; aqui
// revalidamos com a MESMA JWT_SECRET nas rotas sensíveis.
//
// `enforce` fica desligado por padrão (testes/local usam Sanctum); em
// produção o overlay do k8s liga GATEWAY_JWT_ENFORCE=true.

return [
    'jwt' => [
        'enforce' => (bool) env('GATEWAY_JWT_ENFORCE', false),
        'secret' => env('JWT_SECRET'),
        'issuer' => env('JWT_ISSUER', 'oficina-auth'),
    ],
];
