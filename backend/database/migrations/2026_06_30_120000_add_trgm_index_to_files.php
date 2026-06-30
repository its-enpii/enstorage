<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambah GIN trigram index di files.name agar query fuzzy
     * `name % :q` (pg_trgm) pakai index, bukan sequential scan.
     *
     * Sama pola dengan idx_folders_path_trgm di migration folders.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS idx_files_name_trgm ON files USING gin (name gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_files_name_trgm');
    }
};