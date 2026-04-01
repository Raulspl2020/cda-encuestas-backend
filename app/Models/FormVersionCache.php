<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormVersionCache extends Model
{
    protected $table = 'form_versions_cache';

    protected $fillable = [
        'sid',
        'version',
        'version_hash',
        'is_active',
        'published_at',
        'active_from',
        'active_to',
        'payload_json',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'active_from' => 'datetime',
        'active_to' => 'datetime',
    ];
}
