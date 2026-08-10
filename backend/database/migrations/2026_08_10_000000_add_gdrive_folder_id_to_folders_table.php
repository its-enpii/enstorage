<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->string('gdrive_folder_id')->nullable()->after('path');
            $table->index('gdrive_folder_id');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropIndex(['gdrive_folder_id']);
            $table->dropColumn('gdrive_folder_id');
        });
    }
};
