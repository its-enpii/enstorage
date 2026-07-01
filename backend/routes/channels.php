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
|
| 2. Folder events (per user — folder model has no client_key):
|      folder-{user_id}.{folder_id|root}
|    Folder changes apply across all of the user's client_keys/devices.
|    Frontend subscribes to BOTH families so it sees every event.
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
    if (! $user) {
        return false;
    }

    // Verify user owns this client_key (any file with this key proves it).
    $ownsClientKey = FileModel::query()
        ->where('user_id', $user->id)
        ->where('client_key', $clientKey)
        ->exists();

    if (! $ownsClientKey) {
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
    if (! $user) {
        return false;
    }

    // URL userId must match the authenticated user — no cross-user sniff.
    if ((string) $user->id !== $userId) {
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
