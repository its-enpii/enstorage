<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metadata kontekstual hasil ekstraksi file (dimensi gambar, EXIF,
     * durasi audio/video, jumlah halaman PDF, isi archive, statistik teks).
     *
     * Disimpan sebagai JSONB (pola sama dengan `activity_logs.metadata`) —
     * nullable untuk file lama yang di-upload sebelum fitur ekstraksi ada.
     * Ekstraksi dilakukan oleh `App\Services\FileMetadataExtractor` di dalam
     * `UploadFileJob` dan tidak pernah menggagalkan upload.
     */
    public function up(): void
    {
        if (Schema::hasColumn('files', 'metadata')) {
            return; // idempotent
        }

        Schema::table('files', function (Blueprint $table) {
            $table->jsonb('metadata')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('files', 'metadata')) {
            return;
        }

        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
