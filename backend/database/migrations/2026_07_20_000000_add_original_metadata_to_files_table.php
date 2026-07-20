<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom metadata device untuk client-side dedup tanpa hash.
     *
     * Saat Auto Backup jalan, HP kirim (path, mtime_ms, size) per file.
     * Server lookup composite index (user_id, original_path,
     * original_mtime_ms, original_size) — kalau match, file sudah ada
     * di server, mobile skip upload (dan skip hashing konten).
     *
     * Kolom nullable untuk file lama (di-upload sebelum fitur Auto
     * Backup) — endpoint /files/by-metadata skip row dengan NULL.
     *
     * Index composite per user supaya lookup O(log N) via B-tree
     * — 2340 file batch query selesai <1 detik.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->text('original_path')->nullable();
            $table->bigInteger('original_mtime_ms')->nullable();
            $table->unsignedBigInteger('original_size')->nullable();

            // Composite index — lookup "user punya file dengan path+mtime+size ini?"
            // Index urutan: equality (user_id) dulu, lalu tuple (path, mtime, size).
            $table->index(
                ['user_id', 'original_path', 'original_mtime_ms', 'original_size'],
                'idx_files_user_dedup_metadata',
            );
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex('idx_files_user_dedup_metadata');
            $table->dropColumn(['original_path', 'original_mtime_ms', 'original_size']);
        });
    }
};
