<?php

namespace App\Support;

use App\Models\File as FileModel;
use Illuminate\Broadcasting\PrivateChannel;

/**
 * Helper for the canonical Reverb broadcast channel names.
 *
 * Three scopes:
 *
 * 1. File events (per client_key — known device):
 *    client-{client_key}.folder.{folder_id|'root'}
 *    One client_key may be in use by multiple devices of the same user;
 *    folder_id scopes the broadcast to subscribers viewing that folder.
 *
 * 2. Folder events (per user — folder model has no client_key):
 *    folder-{user_id}.{folder_id|'root'}
 *    Folder changes apply across all of the user's client_keys/devices.
 *
 * 3. File events catch-all (per user — external API / unknown device):
 *    user-{user_id}.folder.{folder_id|'root'}
 *    Used when the file event's client_key did NOT come from a real
 *    device the user controls (e.g. external API upload with no
 *    device key, or server-generated ULID). Reaches every tab of the
 *    user so the UI stays in sync regardless of upload source.
 *
 * `null` folder_id → 'root' (broadcast to every tab/screen owned by
 * the channel scope).
 *
 * The Pusher wire format uses dash separators between the channel
 * group ("client", "folder", "user") and the leading identifier
 * (client_key or user_id) so the broadcast pattern can match it
 * without ambiguity. The rest of the path uses dots, mirroring how
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

    public static function userFile(string $userId, ?string $folderId): string
    {
        return 'user-'.$userId.'.folder.'.($folderId ?? 'root');
    }

    /**
     * Pick the right channel(s) for a file event based on whether the
     * file's `client_key` was supplied by a known device or came from
     * somewhere else (server-generated ULID, external API).
     *
     * Routing rule:
     *   - client_key_origin = 'client' AND the key appears in the
     *     user's set of device-supplied keys → route to client.*
     *     (the owning tab's optimistic-update path).
     *   - otherwise → route to user.* (every tab of the user).
     *
     * The two are mutually exclusive: an event goes to exactly one
     * channel family, so subscribers receive each event exactly once
     * without per-tab dedup logic.
     */
    public static function fileEventChannels(FileModel $file): array
    {
        $userDeviceKeys = FileModel::query()
            ->where('user_id', $file->user_id)
            ->where('client_key_origin', 'client')
            ->pluck('client_key')
            ->all();

        $isFromKnownDevice = in_array($file->client_key, $userDeviceKeys, true);

        if ($isFromKnownDevice) {
            return [new PrivateChannel(self::file($file->client_key, $file->folder_id))];
        }

        return [new PrivateChannel(self::userFile((string) $file->user_id, $file->folder_id))];
    }

    /**
     * Same routing decision as fileEventChannels() but takes plain
     * scalars — used by events that fire AFTER the file row is
     * gone from the DB (e.g. FileDeletedBroadcast). The caller must
     * capture `user_id`, `client_key`, and `folder_id` from the
     * model before deletion.
     */
    public static function fileEventChannelsForDeleted(
        string $userId,
        string $clientKey,
        ?string $folderId,
    ): array {
        $userDeviceKeys = FileModel::query()
            ->where('user_id', $userId)
            ->where('client_key_origin', 'client')
            ->pluck('client_key')
            ->all();

        $isFromKnownDevice = in_array($clientKey, $userDeviceKeys, true);

        if ($isFromKnownDevice) {
            return [new PrivateChannel(self::file($clientKey, $folderId))];
        }

        return [new PrivateChannel(self::userFile($userId, $folderId))];
    }
}

