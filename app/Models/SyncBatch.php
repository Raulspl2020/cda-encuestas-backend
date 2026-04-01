<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncBatch extends Model
{
    protected $table = 'sync_batches';

    protected $fillable = [
        'batch_uuid',
        'idempotency_key',
        'device_id',
        'status',
        'accepted_count',
        'rejected_count',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(MobileDevice::class, 'device_id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(SyncInterview::class, 'sync_batch_id');
    }
}
