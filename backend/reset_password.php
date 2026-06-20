<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$email = $argv[1] ?? 'owner@enstorage.local';
$plain = $argv[2] ?? 'password';

$hash = Illuminate\Support\Facades\Hash::make($plain);
$count = Illuminate\Support\Facades\DB::table('users')
    ->where('email', $email)
    ->update(['password' => $hash]);

echo "Updated $count user(s). New hash for $email: $hash\n";
