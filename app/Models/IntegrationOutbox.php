<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationOutbox extends Model
{
    protected $table = 'integration_outbox';

    protected $fillable = [
        'outbox_ref',
        'event_type',
        'aggregate_type',
        'aggregate_ref',
        'payload',
        'schema_version',
        'occurred_at',
        'available_at',
        'published_at',
        'attempt_count',
        'last_attempt_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'schema_version' => 'integer',
        'occurred_at' => 'datetime',
        'available_at' => 'datetime',
        'published_at' => 'datetime',
        'attempt_count' => 'integer',
        'last_attempt_at' => 'datetime',
    ];
}
