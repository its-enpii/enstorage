<?php

use App\Models\File as FileModel;
use App\Models\Folder;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Two channel families, both private (Pusher prefix "private-..." auto
| applied by PrivateChannel):
|
| 1. File events (per client_key):
|      client-{client_key}.folder.{folder_id|root}
|    One client_key may be in use by multiple devices of the same user.
|    Folder id scopes the broadcast to subscribers viewing that folder.
|    Used for file events where the client_key came from a known device.
|
| 2. Folder events (per user — folder model has no client_key):
|      folder-{user_id}.{folder_id|root}
|    Folder changes apply across all of the user's client_keys/devices.
|
| 3. File event catch-all (per user):
|      user-{user_id}.folder.{folder_id|root}
|    Used for file events whose client_key did NOT come from a known
|    device (server-generated ULID, external API upload). Every tab of
|    the user subscribes to this channel so the UI stays in sync even
|    for users with no client_key yet (e.g. freshly registered, or
|    uploaded via curl/external integration).
|
| Auth closure receives `$user` resolved by AuthApiKey middleware
| (Sanctum Bearer OR X-API-Key). Returning false denies the subscription
| attempt at /broadcasting/auth with HTTP 403.
|
| DB cost: 1 query per subscribe to verify ownership. Acceptable for
| typical usage (handful of tabs). If a malicious client subscribes to
| many permutations we can short-circuit with a per-request
| user->client_keys cache in AuthApiKey middleware — left as future
| optimization.
|
*/

Broadcast::channel('client-{clientKey}.folder.{folderId}', function ($user, string $clientKey, string $folderId) {
    \Illuminate\Support\Facades\Log::debug('broadcast.client.auth', [
        'user_id' => $user?->id,
        'client_key' => $clientKey,
        'folder_id' => $folderId,
    ]);
    if (! $user) {
        return false;
    }

    // Verify user owns this client_key (any file with this key proves it).
    $ownsClientKey = FileModel::query()
        ->where('user_id', $user->id)
        ->where('client_key', $clientKey)
        ->exists();

    if (! $ownsClientKey) {
        \Illuminate\Support\Facades\Log::debug('broadcast.client.no-key', [
            'user_id' => $user->id,
            'client_key' => $clientKey,
        ]);
        return false;
    }

    // 'root' = no specific folder — allow all folders the user can see.
    if ($folderId === 'root') {
        return true;
    }

    // Specific folder — must belong to this user.
    return Folder::query()
        ->where('id', $folderId)
        ->where('user_id', $user->id)
        ->exists();
});

// Folder event channel — no client_key needed because folders belong to
// users, not to client_keys. Any of the user's devices/devices using
// any client_key can subscribe.
Broadcast::channel('folder-{userId}.{folderId}', function ($user, string $userId, string $folderId) {
    \Illuminate\Support\Facades\Log::debug('broadcast.folder.auth', [
        'user_id' => $user?->id,
        'url_user_id' => $userId,
        'folder_id' => $folderId,
    ]);
    if (! $user) {
        return false;
    }

    // URL userId must match the authenticated user — no cross-user sniff.
    if ((string) $user->id !== $userId) {
        \Illuminate\Support\Facades\Log::debug('broadcast.folder.mismatch', [
            'auth_user_id' => $user->id,
            'url_user_id' => $userId,
        ]);
        return false;
    }

    // 'root' = top-level; always allow.
    if ($folderId === 'root') {
        return true;
    }

    // Specific folder — must belong to this user.
    return Folder::query()
        ->where('id', $folderId)
        ->where('user_id', $user->id)
        ->exists();
});

// File event catch-all channel — per user, no client_key required. Used
// for file events whose `client_key` was not supplied by a known device
// (server-generated ULID or external API upload). Every tab of the user
// subscribes so it sees uploads triggered outside the device flow.
Broadcast::channel('user-{userId}.folder.{folderId}', function ($user, string $userId, string $folderId) {
    \Illuminate\Support\Facades\Log::debug('broadcast.user_file.auth', [
        'user_id' => $user?->id,
        'url_user_id' => $userId,
        'folder_id' => $folderId,
    ]);
    if (! $user) {
        return false;
    }

    if ((string) $user->id !== $userId) {
        \Illuminate\Support\Facades\Log::debug('broadcast.user_file.mismatch', [
            'auth_user_id' => $user->id,
            'url_user_id' => $userId,
        ]);
        return false;
    }

    if ($folderId === 'root') {
        return true;
    }

    return Folder::query()
        ->where('id', $folderId)
        ->where('user_id', $user->id)
        ->exists();
});
