<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('folders', 'description')) {
            Schema::table('folders', function (Blueprint $table) {
                $table->text('description')->nullable();
            });
        }

        if (! Schema::hasColumn('folders', 'icon')) {
            Schema::table('folders', function (Blueprint $table) {
                $table->string('icon', 100)->nullable();
            });
        }

        if (! Schema::hasColumn('folders', 'logo_url')) {
            Schema::table('folders', function (Blueprint $table) {
                $table->string('logo_url', 1000)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('folders', 'description')) {
            Schema::table('folders', function (Blueprint $table) {
                $table->dropColumn('description');
            });
        }

        if (Schema::hasColumn('folders', 'icon')) {
            Schema::table('folders', function (Blueprint $table) {
                $table->dropColumn('icon');
            });
        }

        if (Schema::hasColumn('folders', 'logo_url')) {
            Schema::table('folders', function (Blueprint $table) {
                $table->dropColumn('logo_url');
            });
        }
    }
};
