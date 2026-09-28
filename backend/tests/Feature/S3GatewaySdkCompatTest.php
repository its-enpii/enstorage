<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Kompatibilitas AWS SDK baru (>= 3.337): SDK menandatangani
 * `x-amz-checksum-crc32` dan memakai `UNSIGNED-PAYLOAD` sebagai payload hash,
 * sementara sebagian versi mengirim hash body sebenarnya. Gateway harus
 * menerima kedua bentuk selama signature-nya sahih.
 */
class S3GatewaySdkCompatTest extends TestCase
{
    use RefreshDatabase;

    private string $accessKey;

    private string $secret;

    private string $apiKey;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->apiKey = (string) app(ApiKeyService::class)
            ->create((string) $this->user->id, 'sdk-compat', ['read', 'write', 'delete'])[1];

        [, $prefix, $secret] = explode('_', $this->apiKey);
        $this->accessKey = 'en_'.$prefix;
        $this->secret = $secret;
    }

    /** Bangun signature seperti SDK: sertakan header tambahan pada SignedHeaders. */
    private function sign(
        string $method,
        string $uri,
        string $body,
        array $extraSignedHeaders,
        string $payloadHash = 'UNSIGNED-PAYLOAD',
        bool $includeContentSha256 = true,
    ): array {
        $parts = parse_url((string) config('app.url'));
        $host = $parts['host'] ?? 'localhost';
        $scheme = $parts['scheme'] ?? 'http';
        $port = $parts['port'] ?? null;
        $isDefault = ($scheme === 'http' && ($port === null || $port === 80))
            || ($scheme === 'https' && ($port === null || $port === 443));
        if (! $isDefault && $port !== null) {
            $host .= ':'.$port;
        }

        $amzDate = now()->utc()->format('Ymd\THis\Z');
        $dateStamp = substr($amzDate, 0, 8);

        $headers = [
            'host' => $host,
            'x-amz-date' => $amzDate,
        ] + $extraSignedHeaders;

        if ($includeContentSha256) {
            $headers = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate]
                + $extraSignedHeaders;
        }

        $signedHeaders = implode(';', array_keys($headers));
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.$value."\n";
        }

        $canonicalRequest = implode("\n", [
            $method, $uri, '', $canonicalHeaders, $signedHeaders, $payloadHash,
        ]);

        $scope = "{$dateStamp}/us-east-1/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4'.$this->secret, true);
        $kRegion = hash_hmac('sha256', 'us-east-1', $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return [
            'Authorization' => sprintf(
                'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
                $this->accessKey, $scope, $signedHeaders,
                hash_hmac('sha256', $stringToSign, $kSigning),
            ),
            'X-Amz-Date' => $amzDate,
            'X-Amz-Content-Sha256' => $payloadHash,
            'include_content_sha256' => $includeContentSha256,
        ];
    }

    public function test_accepts_signed_checksum_crc32_header(): void
    {
        Bus::fake();

        $body = 'checksum payload';
        $uri = '/s3/sidbm/checksum.txt';
        $checksum = base64_encode(hash('crc32b', $body, true));

        $headers = $this->sign('PUT', $uri, $body, [
            'x-amz-checksum-crc32' => $checksum,
        ]);

        $response = $this->call('PUT', '/api/v1'.$uri, [], [], [], [
            'HTTP_AUTHORIZATION' => $headers['Authorization'],
            'HTTP_X_AMZ_DATE' => $headers['X-Amz-Date'],
            'HTTP_X_AMZ_CONTENT_SHA256' => $headers['X-Amz-Content-Sha256'],
            'HTTP_X_AMZ_CHECKSUM_CRC32' => $checksum,
            'CONTENT_TYPE' => 'application/octet-stream',
        ], $body);

        $response->assertStatus(200);
    }

    public function test_accepts_real_body_hash_instead_of_unsigned_payload(): void
    {
        Bus::fake();

        $body = 'body hash payload';
        $uri = '/s3/sidbm/bodyhash.txt';
        $realHash = hash('sha256', $body);

        $headers = $this->sign('PUT', $uri, $body, [], $realHash);

        $response = $this->call('PUT', '/api/v1'.$uri, [], [], [], [
            'HTTP_AUTHORIZATION' => $headers['Authorization'],
            'HTTP_X_AMZ_DATE' => $headers['X-Amz-Date'],
            'HTTP_X_AMZ_CONTENT_SHA256' => $realHash,
            'CONTENT_TYPE' => 'application/octet-stream',
        ], $body);

        $response->assertStatus(200);
    }

    public function test_accepts_unsigned_payload_without_sha256_header(): void
    {
        Bus::fake();

        $body = 'unsigned payload';
        $uri = '/s3/sidbm/unsigned.txt';

        // Sesuai perilaku nyata SDK + proxy: header TIDAK ikut ditandatangani
        // dan tidak dikirim sama sekali.
        $headers = $this->sign('PUT', $uri, $body, [], 'UNSIGNED-PAYLOAD', includeContentSha256: false);

        $response = $this->call('PUT', '/api/v1'.$uri, [], [], [], [
            'HTTP_AUTHORIZATION' => $headers['Authorization'],
            'HTTP_X_AMZ_DATE' => $headers['X-Amz-Date'],
            'CONTENT_TYPE' => 'application/octet-stream',
        ], $body);

        $response->assertStatus(200);
    }
}
