<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$email = $argv[1] ?? 'owner@enstorage.local';
$plain = $argv[2] ?? 'password';

$hash = Hash::make($plain);
$count = DB::table('users')
    ->where('email', $email)
    ->update(['password' => $hash]);

echo "Updated $count user(s). New hash for $email: $hash\n";
