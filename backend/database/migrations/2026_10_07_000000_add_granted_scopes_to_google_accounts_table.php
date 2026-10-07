<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_accounts', function (Blueprint $table) {
            // Daftar scope yang benar-benar di-grant Google untuk token akun ini,
            // dipisah spasi (format asli respons token endpoint). Nullable:
            // akun yang terhubung sebelum kolom ini ada tidak punya datanya →
            // dianggap legacy dan diminta reconnect.
            $table->text('granted_scopes')->nullable()->after('gdrive_root_folder_id');
        });
    }

    public function down(): void
    {
        Schema::table('google_accounts', function (Blueprint $table) {
            $table->dropColumn('granted_scopes');
        });
    }
};
