<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tambah kolom untuk mendukung chunked upload.
     *
     * File besar (>1GB) di-upload dalam beberapa bagian (chunk).
     * Kolom is_chunked menandai apakah file ini di-upload via
     * chunked flow. total_chunks menyimpan jumlah chunk yang
     * diharapkan, received_chunks melacak berapa chunk yang sudah
     * diterima, dan total_size menyimpan ukuran total yang
     * diharapkan dari init request.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->boolean('is_chunked')->default(false);
            $table->integer('total_chunks')->nullable();
            $table->integer('received_chunks')->default(0);
            $table->bigInteger('total_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['is_chunked', 'total_chunks', 'received_chunks', 'total_size']);
        });
    }
};