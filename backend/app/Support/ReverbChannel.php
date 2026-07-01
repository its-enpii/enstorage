<?php

namespace App\Support;

/**
 * Helper for the canonical Reverb broadcast channel names.
 *
 * Two scopes:
 *
 * 1. File events (per client_key):
 *    client-{client_key}.folder.{folder_id|'root'}
 *    One client_key may be in use by multiple devices of the same user;
 *    folder_id scopes the broadcast to subscribers viewing that folder.
 *
 * 2. Folder events (per user — folder model has no client_key):
 *    folder-{user_id}.{folder_id|'root'}
 *    Folder changes apply across all of the user's client_keys/devices.
 *    Frontend subscribes to BOTH file-scoped and folder-scoped channels
 *    so it sees every event regardless of source.
 *
 * `null` folder_id → 'root' (broadcast to every tab/screen owned by
 * the channel scope).
 *
 * The Pusher wire format uses dash separators between the channel
 * group ("client", "folder") and the leading identifier (client_key
 * or user_id) so the broadcast pattern can match it without
 * ambiguity. The rest of the path uses dots, mirroring how
 * routes/channels.php declares the patterns.
 */
final class ReverbChannel
{
    public static function file(string $clientKey, ?string $folderId): string
    {
        return 'client-'.$clientKey.'.folder.'.($folderId ?? 'root');
    }

    public static function folder(string $userId, ?string $folderId): string
    {
        return 'folder-'.$userId.'.'.($folderId ?? 'root');
    }
}
