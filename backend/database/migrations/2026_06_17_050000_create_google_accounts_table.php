<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('label');
            $table->string('email');
            $table->text('access_token');   // encrypted
            $table->text('refresh_token');  // encrypted
            $table->timestampTz('token_expires_at')->nullable();
            $table->string('gdrive_root_folder_id')->nullable();
            $table->bigInteger('quota_total')->default(0);
            $table->bigInteger('quota_used')->default(0);
            $table->timestampTz('quota_synced_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'email']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE google_accounts ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('google_accounts');
    }
};
