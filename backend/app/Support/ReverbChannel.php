<?php

namespace App\Support;

use App\Models\File as FileModel;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Helper for the canonical Reverb broadcast channel names.
 *
 * Single per-user broadcast channel: `user-{user_id}`. Every event for a
 * user — file + folder, server-origin + client-origin — fans out on this
 * one channel. Subscribed clients (web / mobile) do the view-side filter
 * by inspecting the payload's `folder_id` / `parent_id` against their
 * own current view, so the server never has to know who is viewing what.
 *
 * Wire format: Pusher prefixes `private-` automatically on the wire; the
 * channel names below stay without that prefix.
 */
final class ReverbChannel
{
    public static function user(string $userId): string
    {
        return 'user-'.$userId;
    }

    /**
     * One channel for any file event: `user-{userId}`. Routing no longer
     * branches on `client_key_origin` — the original device receives the
     * same broadcast as every other tab of the user, and any dedup /
     * view-routing is the client's job (see `matchesView()` in the web
     * and mobile handlers).
     */
    public static function fileEventChannels(FileModel $file): array
    {
        return [new PrivateChannel(self::user((string) $file->user_id))];
    }

    /**
     * Same routing decision as fileEventChannels() but takes plain
     * scalars — used by events that fire AFTER the file row is gone
     * from the DB (e.g. FileDeletedBroadcast). The caller must capture
     * `user_id` from the model before deletion.
     */
    public static function fileEventChannelsForDeleted(
        string $userId,
        string $clientKey, // kept in signature for call-site compat; unused for routing now.
        ?string $folderId, // ditto.
    ): array {
        return [new PrivateChannel(self::user($userId))];
    }
}
