<?php

namespace Domain\Saga\Application;

use Illuminate\Support\Str;

/** X-Request-Id da requisição corrente (RequestId middleware) ou um novo id. */
final class CorrelationId
{
    public static function current(): string
    {
        $fromRequest = request()->header('X-Request-Id');

        return is_string($fromRequest) && $fromRequest !== '' ? $fromRequest : (string) Str::uuid();
    }
}
