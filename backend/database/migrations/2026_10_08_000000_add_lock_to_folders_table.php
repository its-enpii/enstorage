<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fitur kunci folder (folder lock).
     *
     * `is_locked` menandai folder ini terkunci password. Selama ada
     * nenek moyang terkunci, seluruh subtree (subfolder + file) ikut
     * tidak bisa dibaca tanpa unlock sementara (TTL 30 menit).
     *
     * `lock_password_hash` menyimpan hash password (bcrypt) dan TIDAK
     * PERNAH dikembalikan lewat resource/JSON.
     */
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false);
            $table->string('lock_password_hash')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropColumn(['is_locked', 'lock_password_hash']);
        });
    }
};
