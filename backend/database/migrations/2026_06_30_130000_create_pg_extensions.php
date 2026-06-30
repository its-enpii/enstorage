<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Install Postgres extensions yang dipakai di project.
 *
 * pg_trgm: dipakai untuk fuzzy search (idx_folders_path_trgm,
 * idx_files_name_trgm, dan query `name % :q` di SearchController).
 *
 * PENTING: taruh sebelum migration lain yang butuh extension ini,
 * karena CREATE EXTENSION di Postgres bersifat non-transactional —
 * taruh di migration terpisah agar tidak ikut di-rollback oleh
 * `RefreshDatabase` trait (yang wrap migrate dalam transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS pg_trgm');
    }
};