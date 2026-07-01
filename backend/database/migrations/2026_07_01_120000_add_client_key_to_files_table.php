<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: add nullable (sementara) supaya backfill bisa jalan tanpa konflik NOT NULL.
        Schema::table('files', function (Blueprint $table) {
            $table->string('client_key', 128)->nullable()->after('id');
        });

        // Step 2: backfill ULID untuk rows existing yang belum punya client_key.
        // Loop per row biar menghindari tabrakan unik yang mass-update bisa timbulkan
        // dalam satu transaksi parallel.
        DB::table('files')
            ->whereNull('client_key')
            ->orderBy('created_at')
            ->select('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('files')
                        ->where('id', $row->id)
                        ->update(['client_key' => strtolower((string) Str::ulid())]);
                }
            });

        // Step 3: enforce NOT NULL via raw SQL (tanpa doctrine/dbal)
        // dan tambahkan composite unique index per user.
        DB::statement('ALTER TABLE files ALTER COLUMN client_key SET NOT NULL');
        Schema::table('files', function (Blueprint $table) {
            $table->unique(['user_id', 'client_key'], 'uniq_files_user_client_key');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropUnique('uniq_files_user_client_key');
        });
        DB::statement('ALTER TABLE files ALTER COLUMN client_key DROP NOT NULL');
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('client_key');
        });
    }
};
