<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_key_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('api_key_id');
            $table->string('endpoint', 255);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->smallInteger('status_code')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('api_key_id')->references('id')->on('api_keys')->cascadeOnDelete();
            $table->index('api_key_id');
            $table->index(['created_at'], 'idx_api_key_logs_created_at_desc');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_logs');
    }
};
