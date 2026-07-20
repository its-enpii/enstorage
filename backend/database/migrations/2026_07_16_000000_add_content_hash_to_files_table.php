<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom `content_hash` ke `files` untuk client-side dedup.
     *
     * SHA-256 hex (64 char) dari konten file. Nullable agar file lama
     * (di-upload sebelum fitur Auto Backup) tidak butuh backfill —
     * kolom null di-skip oleh endpoint /files/by-hashes.
     *
     * Index composite (user_id, content_hash) supaya lookup
     * "apakah user ini sudah punya file dengan hash X?" O(1) per hash.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable();
            $table->index(['user_id', 'content_hash'], 'idx_files_user_content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex('idx_files_user_content_hash');
            $table->dropColumn('content_hash');
        });
    }
};