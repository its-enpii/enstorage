<?php
/**
 * tools/ws-probe.php
 *
 * Manual WebSocket round-trip test for Reverb. Run inside the
 * enstorage-app container (has curl + jq + REVERB_* env):
 *
 *   docker compose exec app php /var/www/html/tools/ws-probe.php \
 *       <USER_ID> <TOKEN> [FOLDER_ID|root]
 *
 * What it does:
 *   1. POST /broadcasting/auth for `private-user-{USER_ID}.folder.{folderId}`
 *      using Bearer token. Exits non-zero on 403.
 *   2. Reverb HTTP broadcaster trigger: dispatches a synthetic
 *      `FileUploadedBroadcast` for the latest file belonging to USER_ID.
 *      (Reverb accepts the event regardless of auth — broadcaster uses
 *      REVERB_APP_SECRET, not the user's token.)
 *   3. Connects to ws://enstorage-reverb:8080/app/{APP_KEY}, subscribes
 *      the channel, and prints every frame for 5 seconds.
 *
 * If the broadcast fires, you'll see:
 *   {"event":"pusher_internal:subscription_succeeded", ...}
 *   {"event":"App\\Events\\FileUploadedBroadcast", ...}
 *
 * If you only see subscription_succeeded (no App\\Events...), Reverb is
 * receiving the channel but the broadcaster is NOT pushing events
 * onto it. That's a routing decision bug (broadcastOn() returns wrong
 * channel) or the broadcaster HTTP target is unreachable (check
 * REVERB_HOST — must be enstorage-reverb).
 */

if ($argc < 3) {
    fwrite(STDERR, "usage: php ws-probe.php <USER_ID> <TOKEN> [FOLDER_ID|root]\n");
    exit(2);
}

$userId   = $argv[1];
$token    = $argv[2];
$folderId = $argv[3] ?? 'root';
$appKey   = getenv('REVERB_APP_KEY') ?: '';
$appSecret = getenv('REVERB_APP_SECRET') ?: '';
$appId    = getenv('REVERB_APP_ID') ?: '';
$reverbHost = getenv('REVERB_HOST') ?: 'enstorage-reverb';
$reverbPort = (int) (getenv('REVERB_PORT') ?: 8080);
if ($appKey === '' || $appSecret === '' || $appId === '') {
    fwrite(STDERR, "REVERB_APP_KEY/SECRET/ID must be set in env\n");
    exit(2);
}

// 1. Auth the channel via /broadcasting/auth (loopback).
$channelName = "private-user-{$userId}.folder.{$folderId}";
$socketId    = '123456.789';
$toSign      = $socketId . ':' . $channelName;
$signature   = hash_hmac('sha256', $toSign, $appSecret);

$postBody = http_build_query([
    'socket_id'    => $socketId,
    'channel_name' => $channelName,
]);
$ch = curl_init('http://127.0.0.1:80/api/v1/broadcasting/auth');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postBody,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ],
]);
$authResp = curl_exec($ch);
$authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

fprintf(STDERR, "[probe] /broadcasting/auth HTTP %d body=%s\n", $authCode, trim($authResp));
if ($authCode !== 200) {
    exit(3);
}
$authJson = json_decode($authResp, true);
$auth     = $authJson['auth'] ?? '';

// 2. Synthesise a broadcast event by directly POSTing to Reverb's
//    broadcaster endpoint. Reverb expects a multi-event payload —
//    minimal: one event.
$latestFile = \App\Models\File::query()
    ->where('user_id', $userId)
    ->orderByDesc('updated_at')
    ->first();

if (! $latestFile) {
    fwrite(STDERR, "[probe] no files for user {$userId} — cannot fabricate event\n");
    exit(0);
}

$payload = [
    'id'           => $latestFile->id,
    'name'         => $latestFile->name,
    'folder_id'    => $latestFile->folder_id,
    'client_key'   => $latestFile->client_key,
    'mime_type'    => $latestFile->mime_type,
    'size'         => (int) $latestFile->size,
    'uploaded_at'  => $latestFile->uploaded_at?->toIso8601String(),
    'is_starred'   => (bool) $latestFile->is_starred,
];
$body = json_encode($payload, JSON_UNESCAPED_SLASHES);
$md5  = md5($body);

// Reverb validates the broadcaster signature exactly like Pusher:
//   auth_key + ":" + auth_timestamp + ":" + auth_version + ":" + body_md5
$timestamp = time();
$strToSign = "{$appKey}:{$timestamp}:1.0:{$md5}";
$broadcastSig = hash_hmac('sha256', $strToSign, $appSecret);

$query = http_build_query([
    'auth_key'      => $appKey,
    'auth_timestamp' => $timestamp,
    'auth_version'  => '1.0',
    'body_md5'      => $md5,
    'auth_signature' => $broadcastSig,
]);
$broadcasterUrl = "http://{$reverbHost}:{$reverbPort}/apps/{$appId}/events?{$query}";

$ch = curl_init($broadcasterUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
]);
$bcastResp = curl_exec($ch);
$bcastCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$bcastErr  = curl_error($ch);
curl_close($ch);

fprintf(STDERR, "[probe] broadcaster POST HTTP %d err=%s body=%s\n",
    $bcastCode, $bcastErr ?: '(none)', substr(trim((string) $bcastResp), 0, 200));

// 3. Connect WS and listen for 5 seconds.
$wsUrl = "ws://{$reverbHost}:{$reverbPort}/app/{$appKey}";
fprintf(STDERR, "[probe] ws connect %s\n", $wsUrl);

$ws = stream_socket_client(
    $wsUrl,
    $errno, $errstr, 5,
    STREAM_CLIENT_CONNECT,
    stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]),
);
if ($ws === false) {
    fprintf(STDERR, "[probe] WS connect failed: %s (%d)\n", $errstr, $errno);
    exit(4);
}
stream_set_timeout($ws, 1);

// Wait for connection_established.
$established = false;
$start = microtime(true);
while (microtime(true) - $start < 3) {
    $line = fread($ws, 8192);
    if ($line === false || $line === '') break;
    fwrite(STDERR, "[probe] <<< " . trim($line) . "\n");
    $msg = json_decode(trim($line), true);
    if (is_array($msg) && ($msg['event'] ?? '') === 'pusher:connection_established') {
        $established = true;
        break;
    }
}
if (!$established) {
    fwrite(STDERR, "[probe] never got pusher:connection_established\n");
    exit(5);
}

// Subscribe.
$subMsg = json_encode([
    'event' => 'pusher:subscribe',
    'data'  => [
        'auth'     => $auth,
        'channel'  => $channelName,
    ],
]);
fwrite($ws, $subMsg);
fprintf(STDERR, "[probe] >>> %s\n", $subMsg);

$end = microtime(true) + 5;
while (microtime(true) < $end) {
    $line = fread($ws, 8192);
    if ($line === false || $line === '') {
        usleep(50_000);
        continue;
    }
    fwrite(STDERR, "[probe] <<< " . trim($line) . "\n");
}
fclose($ws);
fwrite(STDERR, "[probe] done\n");