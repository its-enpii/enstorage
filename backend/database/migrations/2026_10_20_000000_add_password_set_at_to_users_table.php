<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menandai kapan user menetapkan sendiri kata sandinya.
     *
     * NULL = user belum pernah menetapkan kata sandi sendiri (mis. akun
     * OAuth-only yang password-nya diisi acak) sehingga alur set password
     * pertama tidak mewajibkan `current_password`.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_set_at')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_set_at');
        });
    }
};
