<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('survey_upload_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('file_token')->unique();
            $table->unsignedBigInteger('sid');
            $table->string('interview_uuid');
            $table->string('question_code');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('temp_disk');
            $table->string('temp_path');
            $table->string('title')->nullable();
            $table->text('comment')->nullable();
            $table->string('status')->default('uploaded');
            $table->string('ls_filename')->nullable();
            $table->string('ls_relative_path')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['sid', 'interview_uuid', 'question_code'], 'idx_survey_upload_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_upload_files');
    }
};
