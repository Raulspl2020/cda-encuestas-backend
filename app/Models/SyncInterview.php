<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncInterview extends Model
{
    protected $table = 'sync_interviews';

    protected $fillable = [
        'sync_batch_id',
        'interview_uuid',
        'form_sid',
        'form_version',
        'status',
        'server_ref',
        'error_code',
        'error_message',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(SyncBatch::class, 'sync_batch_id');
    }
}
