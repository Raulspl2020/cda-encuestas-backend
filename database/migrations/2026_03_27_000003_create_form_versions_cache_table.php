<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('form_versions_cache', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sid');
            $table->string('version');
            $table->string('version_hash');
            $table->boolean('is_active')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('active_from')->nullable();
            $table->timestamp('active_to')->nullable();
            $table->longText('payload_json');
            $table->timestamps();

            $table->unique(['sid', 'version']);
            $table->index(['sid', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_versions_cache');
    }
};
