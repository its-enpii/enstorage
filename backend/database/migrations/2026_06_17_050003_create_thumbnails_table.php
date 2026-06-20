<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('thumbnails', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('file_id');
            $table->string('path', 500);
            $table->integer('width');
            $table->integer('height');
            $table->integer('size');          // bytes
            $table->timestampTz('generated_at')->useCurrent();

            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
            $table->unique('file_id');
        });

        DB::statement('ALTER TABLE thumbnails ALTER COLUMN id SET DEFAULT gen_random_uuid()');
    }

    public function down(): void
    {
        Schema::dropIfExists('thumbnails');
    }
};
