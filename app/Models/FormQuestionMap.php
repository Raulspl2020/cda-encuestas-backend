<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormQuestionMap extends Model
{
    protected $table = 'form_question_map';

    protected $fillable = [
        'sid',
        'version',
        'question_code',
        'subquestion_code',
        'internal_ref',
    ];
}
