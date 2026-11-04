<?php

namespace Domain\Saga\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SagaInstanceModel extends Model
{
    use HasUuids;

    protected $table = 'saga_instances';

    protected $fillable = [
        'id',
        'os_id',
        'state',
        'correlation_id',
        'context',
        'history',
        'failure_reason',
        'deadline_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'history' => 'array',
            'deadline_at' => 'immutable_datetime',
        ];
    }
}
