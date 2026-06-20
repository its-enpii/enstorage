<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->boolean('is_starred')->default(false)->index()->after('name');
        });

        Schema::table('files', function (Blueprint $table) {
            $table->boolean('is_starred')->default(false)->index()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropIndex(['is_starred']);
            $table->dropColumn('is_starred');
        });

        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex(['is_starred']);
            $table->dropColumn('is_starred');
        });
    }
};
