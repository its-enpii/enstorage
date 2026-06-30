<?php

namespace App\Services\ApiKey;

use App\Models\ApiKey;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiKeyService
{
    /**
     * Generate key baru.
     * Format: `en_<8 prefix>_<40 random>`. Prefix disimpan plain, hash bcrypt.
     * Return [model, plaintext] — plaintext hanya dikembalikan sekali.
     */
    public function create(
        string $userId,
        string $label,
        array $scopes,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $prefix = Str::lower(Str::random(8));
        $secret = Str::random(40);
        $plaintext = "en_{$prefix}_{$secret}";

        $apiKey = ApiKey::create([
            'user_id' => $userId,
            'label' => $label,
            'key_hash' => password_hash($plaintext, PASSWORD_BCRYPT),
            'key_prefix' => $prefix,
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
            'is_active' => true,
        ]);

        return [$apiKey, $plaintext];
    }

    /**
     * Verify plaintext terhadap key_prefix → bcrypt compare.
     * Return ApiKey atau null.
     */
    public function verify(string $plaintext): ?ApiKey
    {
        $parts = explode('_', $plaintext);
        if (count($parts) !== 3 || $parts[0] !== 'en') {
            return null;
        }
        $prefix = Str::lower($parts[1]);

        $candidate = ApiKey::where('key_prefix', $prefix)
            ->where('is_active', true)
            ->first();

        if (! $candidate) {
            return null;
        }
        if (! password_verify($plaintext, $candidate->key_hash)) {
            return null;
        }
        if (! $candidate->isUsable()) {
            return null;
        }

        return $candidate;
    }

    public function touch(ApiKey $key): void
    {
        $key->forceFill(['last_used_at' => now()])->save();
    }
}
