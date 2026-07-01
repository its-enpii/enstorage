<?php
// One-shot diagnostic. Run in the `app` container:
//   docker compose exec app php /var/www/html/diagnose_broadcast_auth.php
// Safe to delete after.

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = 'enpiiofficial@gmail.com';
$u = App\Models\User::where('email', $email)->first();
if (! $u) { echo "USER NOT FOUND\n"; exit(1); }

echo "=== User ===\n";
echo "ID: {$u->id}\n";
echo "Email: {$u->email}\n";
echo "Sanctum tokens: " . $u->tokens()->count() . "\n\n";

echo "=== Files in DB ===\n";
$files = App\Models\File::where('user_id', $u->id)->get();
echo "Total rows: " . $files->count() . "\n";
$keys = $files->pluck('client_key')->unique()->values();
echo "Distinct client_keys (" . $keys->count() . "):\n";
foreach ($keys as $k) echo "  - $k\n";

echo "\n=== API Keys ===\n";
$apiKeys = App\Models\ApiKey::where('user_id', $u->id)->get();
echo "Count: " . $apiKeys->count() . "\n";
foreach ($apiKeys as $ak) {
    echo "  id={$ak->id} name={$ak->name} hashed_key_prefix=" . substr($ak->key, 0, 12) . "...\n";
}

echo "\n=== Token user resolution ===\n";
// Simulate what AuthApiKey middleware does
$firstAk = $apiKeys->first();
if ($firstAk) {
    echo "Hashed key in DB: " . substr($firstAk->key, 0, 32) . "\n";
    // Sanctum uses hash('sha256', $token) for lookup; ApiKey uses Hash::check
    if (Hash::check('test-token-here', $firstAk->key)) {
        echo "Hash::check: TRUE (this won't actually match, just structure test)\n";
    } else {
        echo "Hash::check: FALSE (expected — 'test-token-here' is not a real token)\n";
    }
}
