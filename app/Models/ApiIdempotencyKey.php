<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiIdempotencyKey extends Model
{
    protected $table = 'api_idempotency_keys';

    protected $fillable = [
        'idempotency_key',
        'route',
        'request_hash',
        'response_json',
        'status_code',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];
}
