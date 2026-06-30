<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hard switch prefix API key: `enp_` → `en_`.
 *
 * Tidak ada co-existence — key lama format `enp_*` tidak akan match lookup
 * setelah service pakai prefix baru, jadi langsung kosongkan kedua tabel.
 * User akan diminta membuat API key baru via web/app.
 */
return new class extends Migration
{
    public function up(): void
    {
        // api_key_logs FK cascade ke api_keys, tapi truncate parent dulu.
        DB::table('api_keys')->truncate();
        DB::table('api_key_logs')->truncate();
    }

    public function down(): void
    {
        // Tidak bisa restore data yang sudah di-truncate.
        // Down() sengaja no-op agar rollback tidak menambah jejak palsu.
    }
};
