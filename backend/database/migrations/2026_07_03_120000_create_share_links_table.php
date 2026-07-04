<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot polymorphic untuk share links dengan expiry & max_views.
     *
     * Coexists dengan `files.share_token` & `folders.share_token` (legacy):
     * token legacy tetap resolve di viewByToken sebagai fallback untuk
     * backward-compat URL share yang sudah terlanjur dishare keluar.
     */
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('shareable_type', 64);
            $table->uuid('shareable_id');
            $table->string('token', 64)->unique();
            $table->timestampTz('expires_at')->nullable();
            $table->unsignedInteger('max_views')->nullable();
            $table->unsignedInteger('views_count')->default(0);
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->index(['shareable_type', 'shareable_id'], 'idx_share_links_shareable');
            $table->index('user_id');
            // Partial index untuk cleanup job: hanya scan yang punya expires_at.
            $table->index('expires_at', 'idx_share_links_expires_at');
        });

        DB::statement('ALTER TABLE share_links ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
    }
};