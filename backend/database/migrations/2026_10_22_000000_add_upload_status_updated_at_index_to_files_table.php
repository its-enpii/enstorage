<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index komposit untuk cleanup job: `WHERE upload_status = ? AND updated_at < ?`.
     *
     * `files_upload_status_index` (satu kolom) tetap ada; kolom kedua bikin
     * rentang `updated_at` disaring lewat index, bukan lewat scan 85 ribu+
     * baris per akun di vault besar.
     */
    private const INDEX_NAME = 'files_upload_status_updated_at_index';

    public function up(): void
    {
        if (! Schema::hasTable('files') || $this->indexExists()) {
            return; // idempoten
        }

        Schema::table('files', function (Blueprint $table) {
            $table->index(['upload_status', 'updated_at'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('files') || ! $this->indexExists()) {
            return;
        }

        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(self::INDEX_NAME);
        });
    }

    private function indexExists(): bool
    {
        return collect(Schema::getIndexes('files'))
            ->contains(fn (array $index) => $index['name'] === self::INDEX_NAME);
    }
};
