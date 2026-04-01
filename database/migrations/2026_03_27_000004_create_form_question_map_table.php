<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('form_question_map', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sid');
            $table->string('version');
            $table->string('question_code');
            $table->string('subquestion_code')->nullable();
            $table->string('internal_ref');
            $table->timestamps();

            $table->unique(['sid', 'version', 'question_code', 'subquestion_code'], 'uq_form_question_map');
            $table->index(['sid', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_question_map');
    }
};
