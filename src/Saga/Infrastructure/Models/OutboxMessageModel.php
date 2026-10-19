<?php

namespace Domain\Saga\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class OutboxMessageModel extends Model
{
    protected $table = 'outbox_messages';

    protected $fillable = [
        'message_id',
        'type',
        'saga_id',
        'body',
        'attempts',
        'last_error',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'body' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }
}
