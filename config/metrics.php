<?php

// Configuração do endpoint /metrics (promphp/prometheus_client_php).
// Em produção usa Redis (persiste métricas entre workers php-fpm); em
// testes usa memória (não persiste, mas evita depender do Redis).

return [
    'storage' => env('METRICS_STORAGE', 'redis'), // redis | memory
    'namespace' => 'oficina',
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => (int) env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD') ?: null,
        'database' => (int) env('REDIS_METRICS_DB', 2),
        'timeout' => 0.5,
    ],
];
