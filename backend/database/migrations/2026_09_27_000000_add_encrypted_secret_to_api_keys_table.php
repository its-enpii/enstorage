<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Simpan secret API key dalam bentuk terenkripsi (Laravel Crypt).
     *
     * Diperlukan oleh S3 Gateway: verifikasi AWS Signature V4 hanya dapat
     * dilakukan bila server bisa merekonstruksi secret asli untuk
     * menghitung ulang HMAC. `key_hash` (bcrypt) bersifat one-way dan tidak
     * bisa dipakai untuk keperluan ini, sehingga secret plaintext-
     * equivalent disimpan terenkripsi di kolom terpisah.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->text('encrypted_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('encrypted_secret');
        });
    }
};
