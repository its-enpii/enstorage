<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `client_key_origin` column to the `files` table.
 *
 * Distinguishes client-supplied device keys ('client') from
 * server-generated ULIDs ('server'). Used by the broadcast routing
 * decision in ReverbChannel::fileEventChannels() to send events to
 * the right channel family (client.* for known devices, user.* for
 * everything else — including external API uploads).
 *
 * Backfill: any existing row whose `client_key` came from the
 * auto-generate path in FileUploadController (server-assigned ULID)
 * is marked 'server'. We have no way to retroactively know which
 * keys the user supplied (no audit trail), so all existing rows
 * default to 'server' — a safe over-estimate, since 'server' rows
 * route to user.* which is what we want as a fallback for any
 * key whose device-ness is ambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->string('client_key_origin', 16)
                ->nullable()
                ->after('client_key');
        });

        // Existing rows: we cannot distinguish client-supplied from
        // server-generated for the historical data, so default to
        // 'server' (the safer fallback — routes to user.* which is
        // the broadcast channel that reaches every tab of the user).
        DB::table('files')
            ->whereNull('client_key_origin')
            ->update(['client_key_origin' => 'server']);

        // Index for the broadcast routing query, which filters by
        // (user_id, client_key_origin = 'client') to discover the
        // set of real device keys.
        Schema::table('files', function (Blueprint $table) {
            $table->index(
                ['user_id', 'client_key_origin'],
                'idx_files_user_origin',
            );
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropIndex('idx_files_user_origin');
            $table->dropColumn('client_key_origin');
        });
    }
};
