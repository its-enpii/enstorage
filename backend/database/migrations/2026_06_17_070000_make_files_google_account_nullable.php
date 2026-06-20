<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable: akun Google dipilih saat UploadJob memproses,
        // bukan saat endpoint /files/upload (return 202).
        Schema::table('files', function (Blueprint $table) {
            $table->dropForeign(['google_account_id']);
            $table->uuid('google_account_id')->nullable()->change();
        });

        Schema::table('files', function (Blueprint $table) {
            $table->foreign('google_account_id')->references('id')->on('google_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropForeign(['google_account_id']);
            $table->uuid('google_account_id')->nullable(false)->change();
        });

        Schema::table('files', function (Blueprint $table) {
            $table->foreign('google_account_id')->references('id')->on('google_accounts');
        });
    }
};
