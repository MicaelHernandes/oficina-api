<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valida o JWT HS256 emitido pela Lambda de auth (repo 1), usando a mesma
 * JWT_SECRET. Defense-in-depth atrás do API Gateway/Lambda Authorizer.
 *
 * Desligado por padrão (config gateway.jwt.enforce=false) para não conflitar
 * com o Sanctum do staff nos testes/local. Em produção o overlay liga
 * GATEWAY_JWT_ENFORCE=true.
 */
class ValidateGatewayJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('gateway.jwt.enforce')) {
            return $next($request);
        }

        $secret = (string) config('gateway.jwt.secret');
        if ($secret === '') {
            return response()->json(['error' => 'server_error', 'message' => 'JWT não configurado.'], 500);
        }

        $header = (string) $request->header('Authorization', '');
        if (! preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return response()->json(['error' => 'unauthorized', 'message' => 'Token ausente.'], 401);
        }

        try {
            $decoded = JWT::decode($m[1], new Key($secret, 'HS256'));
        } catch (\Throwable $e) {
            return response()->json(['error' => 'unauthorized', 'message' => 'Token inválido.'], 401);
        }

        if (($decoded->iss ?? null) !== config('gateway.jwt.issuer')) {
            return response()->json(['error' => 'unauthorized', 'message' => 'Emissor inválido.'], 401);
        }

        // Disponibiliza o cliente autenticado para os controllers.
        $request->attributes->set('gateway_customer_id', $decoded->sub ?? null);
        $request->attributes->set('gateway_cpf', $decoded->cpf ?? null);

        return $next($request);
    }
}
