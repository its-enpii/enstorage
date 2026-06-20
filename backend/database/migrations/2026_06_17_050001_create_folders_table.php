<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->text('path');
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'parent_id', 'name']);
            $table->index('user_id');
            $table->index('parent_id');
        });

        // Self-FK parent_id ditambahkan setelah tabel ada (workaround untuk self-reference)
        Schema::table('folders', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('folders')->cascadeOnDelete();
        });

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('ALTER TABLE folders ALTER COLUMN id SET DEFAULT gen_random_uuid()');
        DB::statement('CREATE INDEX idx_folders_path_trgm ON folders USING gin (path gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
