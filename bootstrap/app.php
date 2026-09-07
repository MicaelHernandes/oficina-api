<?php

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\PrometheusMetrics;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\ValidateGatewayJwt;
use App\Providers\DomainServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withProviders([
        DomainServiceProvider::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Correlação de requisições (X-Request-Id) o mais cedo possível.
        $middleware->prepend(RequestId::class);
        $middleware->append(ForceJsonResponse::class);
        // Métricas por requisição (latência/status) para o Prometheus.
        $middleware->append(PrometheusMetrics::class);
        // Validação do JWT emitido pela Lambda (defense-in-depth, configurável).
        $middleware->alias([
            'gateway.jwt' => ValidateGatewayJwt::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
