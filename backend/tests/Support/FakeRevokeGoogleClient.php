<?php

namespace Tests\Support;

use Google\Client as GoogleClient;

/**
 * Google Client yang `revokeToken()`-nya tidak menembak HTTP nyata.
 *
 * `revokeToken()` bawaan Google Client membuka koneksi ke endpoint OAuth
 * Google memakai handler default Guzzle. Dalam test itu bukan hanya tidak
 * diinginkan (internet), tetapi juga mengotori state HTTP proses PHP yang
 * dipakai MockHandler test lain (mis. GDriveUnreachableTest): request
 * revoke memakai antrean/state bersama sehingga respons yang harusnya
 * 404/200 tertukar.
 *
 * Client ini mencatat token yang "dicabut" supaya test hapus akun tetap
 * bisa membuktikan `revokeToken()` benar-benar dipanggil tanpa I/O nyata.
 */
class FakeRevokeGoogleClient extends GoogleClient
{
    /** @var array<int, string|null> */
    public array $revokedTokens = [];

    /** @var array<int, GoogleClient|null> */
    public array $revokeClients = [];

    /** @var array<int, string|null> */
    public static array $allRevokedTokens = [];

    public static function reset(): void
    {
        self::$allRevokedTokens = [];
    }

    public function revokeToken($token = null)
    {
        $this->revokedTokens[] = $token;
        $this->revokeClients[] = $this;
        self::$allRevokedTokens[] = $token;
    }
}
