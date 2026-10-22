<?php

namespace Tests\Feature;

use App\Models\User;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadSizeLimitMessageUniformityTest extends TestCase
{
    use RefreshDatabase;

    private const ONE_GIB = 1024 * 1024 * 1024;

    private const CANONICAL_MESSAGE = 'Ukuran file melebihi batas maksimum 1 GB (1.073.741.824 byte).';

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('id');
    }

    public function test_all_oversize_rejection_paths_return_the_same_canonical_message(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $uploadResponse = $this->post('/api/v1/files/upload', [
            'file' => [$this->virtualHugeFile()],
        ]);
        $bulkResponse = $this->post('/api/v1/files/bulk-upload', [
            'files' => [$this->virtualHugeFile('batch.bin')],
        ]);
        $initResponse = $this->postJson('/api/v1/files/upload/init', [
            'file_name' => 'big.bin',
            'mime_type' => 'application/octet-stream',
            'total_size' => 2 * self::ONE_GIB,
            'total_chunks' => 256,
        ]);
        $this->fakeOversizedUrlDownload();
        $urlResponse = $this->postJson('/api/v1/files/upload-from-url', [
            'url' => 'https://93.184.216.34/oversized.bin',
        ]);

        $uploadBody = json_decode((string) $uploadResponse->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $bulkBody = json_decode((string) $bulkResponse->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $initBody = json_decode((string) $initResponse->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $urlBody = json_decode((string) $urlResponse->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $messages = [
            $this->normalizeMessage($uploadBody['data']['errors']['file'][0]),
            $this->normalizeMessage($bulkBody['data']['errors']['files.0']),
            $this->normalizeMessage($initBody['data']['errors']['total_size'][0]),
            $this->normalizeMessage($urlBody['data']['errors']['url'][0]),
        ];

        $this->assertSame(
            array_fill(0, 4, self::CANONICAL_MESSAGE),
            $messages,
            'Semua jalur penolakan ukuran harus mengembalikan kalimat kanonik yang identik.',
        );
    }

    private function virtualHugeFile(string $name = 'huge.bin'): UploadedFile
    {
        $file = UploadedFile::fake()->createWithContent($name, 'x');
        $file->sizeToReport = self::ONE_GIB + 1;

        return $file;
    }

    private function fakeOversizedUrlDownload(): void
    {
        Http::fake(function ($request, array $options) {
            $sink = fopen($options['sink'], 'wb');
            $this->assertIsResource($sink);
            $this->assertTrue(ftruncate($sink, self::ONE_GIB + 1));
            fclose($sink);

            return new class(Http::psr7Response('', 200)) extends FulfilledPromise
            {
                public function then(?callable $onFulfilled = null, ?callable $onRejected = null): PromiseInterface
                {
                    return $this;
                }
            };
        });
    }

    private function normalizeMessage(mixed $message): string
    {
        if (is_array($message)) {
            $message = implode(' ', array_filter($message, is_string(...)));
        }

        $this->assertIsString($message);

        return trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    }
}
