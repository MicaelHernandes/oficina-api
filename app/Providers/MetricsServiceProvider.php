<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis as RedisStorage;

class MetricsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CollectorRegistry::class, function () {
            $storage = config('metrics.storage', 'redis');

            if ($storage === 'redis') {
                $adapter = new RedisStorage([
                    'host' => config('metrics.redis.host'),
                    'port' => config('metrics.redis.port'),
                    'password' => config('metrics.redis.password'),
                    'database' => config('metrics.redis.database'),
                    'timeout' => config('metrics.redis.timeout'),
                    'read_timeout' => config('metrics.redis.timeout'),
                ]);

                return new CollectorRegistry($adapter);
            }

            return new CollectorRegistry(new InMemory);
        });
    }
}
