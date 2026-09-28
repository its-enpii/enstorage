<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Multi-backend storage: file tidak lagi selalu berada di Google Drive.
     *
     * `storage_driver` menentukan backend penyimpanan yang dipakai
     * ('gdrive' | 's3' | 'local'), sementara `storage_path` menyimpan lokasi
     * objek relatif terhadap disk backend (dipakai oleh driver 's3' & 'local').
     * Default 'gdrive' menjaga kompatibilitas data lama.
     */
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('storage_driver', 32)->default('gdrive');
            $table->string('storage_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropColumn(['storage_driver', 'storage_path']);
        });
    }
};
