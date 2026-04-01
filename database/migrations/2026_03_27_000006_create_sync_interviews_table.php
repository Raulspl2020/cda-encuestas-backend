<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sync_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_batch_id')->constrained('sync_batches');
            $table->uuid('interview_uuid')->unique();
            $table->unsignedBigInteger('form_sid');
            $table->string('form_version');
            $table->string('status', 20);
            $table->string('server_ref')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['sync_batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_interviews');
    }
};
