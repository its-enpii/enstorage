<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spanned Chunk Storage: satu File utuh bisa tersebar menjadi beberapa part
 * di beberapa akun Google Drive berbeda. Tiap baris mencatat rentang byte
 * (half-open per part, tetapi kolom end bersifat inklusif) dan objek Drive
 * tempat part tersebut disimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('file_id')->index();
            $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete();
            $table->unsignedInteger('part_number');
            $table->uuid('google_account_id')->index();
            $table->foreign('google_account_id')->references('id')->on('google_accounts')->cascadeOnDelete();
            $table->string('gdrive_file_id');
            $table->unsignedBigInteger('byte_offset_start');
            $table->unsignedBigInteger('byte_offset_end');
            $table->unsignedBigInteger('size');
            $table->string('status', 20)->default('done');
            $table->timestampsTz();

            $table->unique(['file_id', 'part_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_parts');
    }
};
