<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| One private channel per user: `user-{user_id}`. All file and folder
| events for a user (server-origin, client-origin, external API key
| uploads, manual moves, etc.) broadcast on this one channel. Subscribed
| clients filter by current view locally — the server never knows who
| is viewing what, so a tab in folder X still receives events for
| folder Y (used to update sidebar folder card counts, root-level
| toplevel show, etc.).
|
| Auth closure receives `$user` resolved by AuthApiKey middleware
| (Sanctum Bearer OR X-API-Key). Returning false denies the subscription
| attempt at /broadcasting/auth with HTTP 403.
|
*/

Broadcast::channel('user-{userId}', function ($user, string $userId) {
    \Illuminate\Support\Facades\Log::debug('broadcast.user.auth', [
        'user_id' => $user?->id,
        'url_user_id' => $userId,
    ]);
    if (! $user) {
        return false;
    }
    return (string) $user->id === $userId;
});
