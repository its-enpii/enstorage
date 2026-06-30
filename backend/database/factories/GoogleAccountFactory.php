<?php

namespace Database\Factories;

use App\Models\GoogleAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoogleAccount>
 */
class GoogleAccountFactory extends Factory
{
    protected $model = GoogleAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => 'Akun '.fake()->word(),
            'email' => fake()->unique()->safeEmail(),
            'access_token' => Str::random(40),
            'refresh_token' => Str::random(40),
            'token_expires_at' => now()->addHour(),
            'gdrive_root_folder_id' => 'root_'.Str::random(10),
            'quota_total' => 15 * 1024 * 1024 * 1024,
            'quota_used' => 0,
            'quota_synced_at' => now(),
            'is_active' => true,
        ];
    }
}