<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('folder_id')->nullable();
            $table->uuid('google_account_id');
            $table->string('name');
            $table->string('original_name');
            $table->string('mime_type');
            $table->bigInteger('size');                 // bytes
            $table->string('gdrive_file_id')->unique();
            $table->text('shareable_link')->nullable();
            $table->string('upload_status', 20)->default('pending');
            $table->timestampTz('uploaded_at')->nullable();
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('folder_id')->references('id')->on('folders')->nullOnDelete();
            $table->foreign('google_account_id')->references('id')->on('google_accounts');

            $table->index('user_id');
            $table->index('folder_id');
            $table->index('google_account_id');
            $table->index('upload_status');
            $table->index('mime_type');
            $table->index(['created_at'], 'idx_files_created_at_desc');
        });

        DB::statement('ALTER TABLE files ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
