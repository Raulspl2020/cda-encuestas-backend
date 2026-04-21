<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SurveyUploadFile extends Model
{
    protected $table = 'survey_upload_files';

    protected $fillable = [
        'file_token',
        'sid',
        'interview_uuid',
        'question_code',
        'original_name',
        'mime_type',
        'size_bytes',
        'temp_disk',
        'temp_path',
        'title',
        'comment',
        'status',
        'ls_filename',
        'ls_relative_path',
        'consumed_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'consumed_at' => 'datetime',
    ];
}
