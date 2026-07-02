<?php
/**
 * tools/ws-bcast-test.php
 *
 * Reverb broadcast round-trip without any user auth. Useful as a
 * smoke test when no user token is to hand.
 *
 *   docker compose exec app php /var/www/html/tools/ws-bcast-test.php
 *
 * What it does:
 *   1. Verify Reverb is reachable at REVERB_HOST:REVERB_PORT (HTTP).
 *   2. Verify Reverb accepts the broadcaster HMAC signature for our
 *      APP_ID+APP_KEY+APP_SECRET (smallest legal event payload).
 *   3. Open WS, subscribe to a channel via /broadcasting/auth with a
 *      same-user token, dispatch a fresh broadcast on that channel,
 *      and print every frame the WS sees for 4 seconds.
 *
 * Use this to localise where a broadcast dies between
 *   (a) Laravel's PusherBroadcaster -> Reverb HTTP endpoint
 *   (b) Reverb internal pub/sub -> WS subscriber
 *
 * For step 3 you must pass a TOKEN with a valid Bearer login; the
 * listener opens the WS subscription directly. Without a token the
 * script prints the broadcaster side and stops (steps 1+2).
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: php ws-bcast-test.php <USER_TOKEN> [FOLDER_ID|root]\n");
    fwrite(STDERR, "       (token is optional — script runs steps 1+2 without it)\n");
}

$token    = $argv[1] ?? '';
$folderId = $argv[2] ?? 'root';

$appKey    = getenv('REVERB_APP_KEY')    ?: '';
$appSecret = getenv('REVERB_APP_SECRET') ?: '';
$appId     = getenv('REVERB_APP_ID')     ?: '';
$reverbHost = getenv('REVERB_HOST') ?: 'enstorage-reverb';
$reverbPort = (int) (getenv('REVERB_PORT') ?: 8080);

if ($appKey === '' || $appSecret === '' || $appId === '') {
    fwrite(STDERR, "REVERB_APP_KEY/SECRET/ID unset in this container env\n");
    exit(2);
}

// ─── Step 1: TCP/HTTP reachability ────────────────────────────────
fprintf(STDERR, "[bcast] Reverb at http://%s:%d (app_id=%s, app_key=%s)\n",
    $reverbHost, $reverbPort, $appId, $appKey);

$ch = curl_init("http://{$reverbHost}:{$reverbPort}/");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 3,
]);
curl_exec($ch);
$reachCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$reachErr  = curl_error($ch);
curl_close($ch);

if ($reachCode === 0 && $reachErr !== '') {
    fwrite(STDERR, "[bcast] step 1 FAIL: cannot reach Reverb — {$reachErr}\n");
    fwrite(STDERR, "[bcast]   hint: REVERB_HOST must be the docker-network\n");
    fwrite(STDERR, "[bcast]         hostname (enstorage-reverb), NOT 0.0.0.0\n");
    exit(3);
}
fprintf(STDERR, "[bcast] step 1 OK: Reverb reachable (HTTP %d on /, expected 404 — no GET route)\n", $reachCode);

// ─── Step 2: broadcaster HMAC accept ──────────────────────────────
// Reverb's signature scheme (see vendor/laravel/reverb/.../Controller.php):
//   sig = HMAC-SHA256(secret,
//       METHOD + "\n" + path + "\n" + sorted_query_params)
// Pusher docs say similar but Reverb implements the exact same scheme
// over `path + sorted_query` instead of `key:ts:ver:body_md5`. Body MD5
// is only added to query when the body is non-empty.
//
// Query params (sorted alphabetically when signed):
//   auth_key, auth_signature, auth_timestamp, auth_version, body_md5
$now     = time();
$eventName = 'bcast-test';
$channel   = 'test-channel';
$dataStr   = json_encode(['hi' => 'bcast-test', 't' => $now], JSON_UNESCAPED_SLASHES);
$body      = json_encode([
    'name'    => $eventName,
    'channel' => $channel,
    'data'    => $dataStr,
], JSON_UNESCAPED_SLASHES);
$bodyMd5   = md5($body);
$path      = "/apps/{$appId}/events";

// Build signed params (sorted alphabetically before concat).
$signed = [
    'auth_key'       => $appKey,
    'auth_timestamp' => (string) $now,
    'auth_version'   => '1.0',
    'body_md5'       => $bodyMd5,
];
ksort($signed);
$signedStr = http_build_query($signed, '', '&', PHP_QUERY_RFC3986);

$strToSign = "POST\n{$path}\n{$signedStr}";
$sig       = hash_hmac('sha256', $strToSign, $appSecret);

$qs = http_build_query([
    'auth_key'       => $appKey,
    'auth_timestamp' => $now,
    'auth_version'   => '1.0',
    'body_md5'       => $bodyMd5,
    'auth_signature' => $sig,
], '', '&', PHP_QUERY_RFC3986);
$url = "http://{$reverbHost}:{$reverbPort}/apps/{$appId}/events?{$qs}";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 5,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

fprintf(STDERR, "[bcast] step 2 broadcaster POST HTTP %d err=%s body=%s\n",
    $code, $err ?: '(none)', trim((string) $resp));

if ($code !== 200) {
    fwrite(STDERR, "[bcast] step 2 FAIL — see body above\n");
    if ($code === 0) {
        fwrite(STDERR, "[bcast]   hint: container cannot reach Reverb —\n");
        fwrite(STDERR, "[bcast]         REVERB_HOST should be 'enstorage-reverb'\n");
        fwrite(STDERR, "[bcast]         (check docker compose env_file for app+worker)\n");
    }
    exit(4);
}

fwrite(STDERR, "[bcast] step 2 OK — broadcaster accepted the HMAC + payload\n");

if ($token === '') {
    fwrite(STDERR, "[bcast] no TOKEN supplied; skipping step 3 (WS subscriber check)\n");
    fwrite(STDERR, "[bcast] run with a token to verify the full push-to-subscriber path\n");
    exit(0);
}

// ─── Step 3: open WS + subscribe + broadcast on the user's channel ──
// We avoid bootstrapping Laravel here — the probe runs in isolation.
// Resolve user_id by querying `personal_access_tokens` directly with
// the database credentials from the environment.
[$userId] = explode('|', $token, 2);
$userId   = (int) $userId;

// Quick DB lookup using pdo directly.
$pdo = new PDO(
    sprintf('pgsql:host=%s;port=%s;dbname=%s',
        getenv('DB_HOST') ?: 'postgres',
        getenv('DB_PORT') ?: '5432',
        getenv('DB_DATABASE') ?: 'enstorage'),
    getenv('DB_USERNAME') ?: 'enstorage',
    getenv('DB_PASSWORD') ?: 'enstorage',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$stmt = $pdo->prepare('SELECT tokenable_id FROM personal_access_tokens WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$resolved = $stmt->fetchColumn();
if (!$resolved) {
    fwrite(STDERR, "[bcast] step 3 token id={$userId} not found in personal_access_tokens\n");
    fwrite(STDERR, "[bcast]   using first user from users table as fallback\n");
    $resolved = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
}
$userId = (string) $resolved;
fprintf(STDERR, "[bcast] step 3 user_id=%s (token=%.6s...)\n", $userId, $token);

$channelName = "private-user-{$userId}.folder.{$folderId}";
$socketId    = '123456.789';
$signature   = hash_hmac('sha256', $socketId . ':' . $channelName, $appSecret);
$postBody = http_build_query(['socket_id' => $socketId, 'channel_name' => $channelName]);
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

fprintf(STDERR, "[bcast] step 3a /broadcasting/auth HTTP %d body=%.80s\n",
    $authCode, trim((string) $authResp));
if ($authCode !== 200) {
    fwrite(STDERR, "[bcast] step 3a FAIL — token invalid or user mismatch\n");
    exit(5);
}
$authJson = json_decode($authResp, true);
$auth     = $authJson['auth'] ?? '';

// Open WS and subscribe.
$ws = stream_socket_client(
    "ws://{$reverbHost}:{$reverbPort}/app/{$appKey}",
    $errno, $errstr, 3,
    STREAM_CLIENT_CONNECT,
);
if ($ws === false) {
    fprintf(STDERR, "[bcast] step 3b WS connect FAIL: %s (%d)\n", $errstr, $errno);
    exit(6);
}
stream_set_timeout($ws, 1);

$start = microtime(true);
$established = false;
while (microtime(true) - $start < 3) {
    $line = fread($ws, 8192);
    if ($line === false || $line === '') break;
    fwrite(STDERR, "[bcast] <<< " . trim($line) . "\n");
    $msg = json_decode(trim($line), true);
    if (is_array($msg) && ($msg['event'] ?? '') === 'pusher:connection_established') {
        $established = true;
        break;
    }
}
if (!$established) {
    fwrite(STDERR, "[bcast] step 3b FAIL — no pusher:connection_established\n");
    exit(7);
}
fwrite($ws, json_encode([
    'event' => 'pusher:subscribe',
    'data'  => ['auth' => $auth, 'channel' => $channelName],
]));
fprintf(STDERR, "[bcast] step 3b subscribed to %s\n", $channelName);

// Fire a fresh synthetic event after subscription is established
// (give the broker ~200ms to register).
usleep(300_000);

$latestStmt = $pdo->prepare(
    'SELECT id, name, folder_id, mime_type, size FROM files '
  . 'WHERE user_id = :uid ORDER BY id DESC LIMIT 1'
);
$latestStmt->execute([':uid' => $userId]);
$latest = $latestStmt->fetch(PDO::FETCH_ASSOC);
if (!$latest) {
    fwrite(STDERR, "[bcast] no files for this user; cannot fabricate event\n");
    exit(0);
}
$payload = [
    'name'    => 'App\\Events\\FileUploadedBroadcast',
    'channel' => $channelName,
    'data'    => json_encode([
        'file_id'   => $latest['id'],
        'name'      => $latest['name'],
        'folder_id' => $latest['folder_id'],
        'mime_type' => $latest['mime_type'],
        'size'      => (int) $latest['size'],
    ], JSON_UNESCAPED_SLASHES),
];
$body     = json_encode($payload, JSON_UNESCAPED_SLASHES);
$md5      = md5($body);
$now      = time();
$path3    = "/apps/{$appId}/events";
$signed3  = [
    'auth_key'       => $appKey,
    'auth_timestamp' => (string) $now,
    'auth_version'   => '1.0',
    'body_md5'       => $md5,
];
ksort($signed3);
$signedStr3 = http_build_query($signed3, '', '&', PHP_QUERY_RFC3986);
$sig       = hash_hmac('sha256', "POST\n{$path3}\n{$signedStr3}", $appSecret);
$qs        = http_build_query([
    'auth_key' => $appKey, 'auth_timestamp' => $now,
    'auth_version' => '1.0', 'body_md5' => $md5, 'auth_signature' => $sig,
], '', '&', PHP_QUERY_RFC3986);
$ch = curl_init("http://{$reverbHost}:{$reverbPort}/apps/{$appId}/events?{$qs}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
]);
curl_exec($ch);
curl_close($ch);
fwrite(STDERR, "[bcast] step 3c fired synthetic event\n");

$end = microtime(true) + 4;
while (microtime(true) < $end) {
    $line = fread($ws, 8192);
    if ($line === false || $line === '') {
        usleep(50_000);
        continue;
    }
    fwrite(STDERR, "[bcast] <<< " . trim($line) . "\n");
}
fclose($ws);
fwrite(STDERR, "[bcast] done\n");