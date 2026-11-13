<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            // Di-set ketika Drive membalas 403/404 untuk folder milik akun ini
            // (folders.gdrive_folder_id menunjuk folder generasi lama yang sudah
            // mati). Di-clear saat folder kembali reachable (audit ulang) atau
            // setelah re-link by-name menemukan folder pengganti yang sah.
            $table->timestampTz('gdrive_unreachable_at')->nullable()->after('gdrive_folder_id');
            $table->index('gdrive_unreachable_at');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropIndex(['gdrive_unreachable_at']);
            $table->dropColumn('gdrive_unreachable_at');
        });
    }
};
