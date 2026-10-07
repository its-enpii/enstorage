<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            // Di-set ketika Drive membalas 403/404 untuk file milik akun ini
            // (token baru dengan `drive.file` tidak lagi melihat file lama yang
            // bukan dibuat app dan belum di-import via Picker). Di-clear saat
            // file kembali reachable (audit ulang / import ulang).
            $table->timestampTz('gdrive_unreachable_at')->nullable()->after('gdrive_file_id');
            $table->index('gdrive_unreachable_at');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['gdrive_unreachable_at']);
            $table->dropColumn('gdrive_unreachable_at');
        });
    }
};
