<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class S3ImageUploadE2ETest extends TestCase
{
    use RefreshDatabase;

    public function test_real_image_upload_and_display_verification(): void
    {
        // TANPA Bus::fake(): UploadFileJob benar-benar berjalan (queue sync).
        // Di lingkungan test tanpa akun Google, job otomatis fallback ke
        // persistent local storage driver.

        // 1. Siapkan user dan API key EnStorage
        $user = User::factory()->create();
        [$apiKey, $plaintext] = app(ApiKeyService::class)->create(
            userId: $user->id,
            label: 'test-e2e-key',
            scopes: ['full'],
        );

        // 2. Buat gambar PNG nyata (10x10 pixel berwarna merah murni)
        $im = imagecreatetruecolor(10, 10);
        $red = imagecolorallocate($im, 255, 0, 0);
        imagefilledrectangle($im, 0, 0, 9, 9, $red);
        ob_start();
        imagepng($im);
        $originalPngBinary = ob_get_clean();
        imagedestroy($im);

        $this->assertNotEmpty($originalPngBinary);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $originalPngBinary);

        // 3. Upload gambar ke EnStorage via S3 Gateway PUT
        $targetUri = '/api/v1/s3/public/logos/test-logo.png';
        $uploadResponse = $this->call(
            'PUT',
            $targetUri,
            [],
            [],
            [],
            [
                'HTTP_X-API-Key' => $plaintext,
                'CONTENT_TYPE' => 'image/png',
            ],
            $originalPngBinary,
        );

        // Assert upload berhasil
        $uploadResponse->assertStatus(200);
        $this->assertSame(md5($originalPngBinary), trim((string) $uploadResponse->headers->get('ETag'), '"'));

        // Pastikan record file tercatat di database & job sudah menyelesaikan
        // seluruh pipeline (status done, driver local, kuota akun Google null).
        $file = File::where('user_id', $user->id)->first();
        $this->assertNotNull($file);
        $this->assertSame('test-logo.png', $file->name);
        $this->assertSame('image/png', $file->mime_type);
        $this->assertSame(strlen($originalPngBinary), $file->size);

        // 2a. Job nyata sudah menyelesaikan upload → status done.
        $file->refresh();
        $this->assertSame(File::STATUS_DONE, $file->upload_status);
        $this->assertSame('local', $file->storage_driver);
        $this->assertNotNull($file->storage_path);
        $this->assertNull($file->google_account_id);
        $this->assertNotNull($file->uploaded_at);

        // 2b. Berkas benar-benar tersimpan di persistent local disk.
        Storage::disk('local')->assertExists($file->storage_path);

        // 3. Temp file sudah di-unlink oleh job.
        $this->assertFileDoesNotExist(storage_path('app/temp/'.$file->id));

        // 4. Ambil gambar kembali via S3 Gateway GET TANPA AUTH (Public Bucket)
        $getResponse = $this->get($targetUri);
        $getResponse->assertStatus(200);
        $getResponse->assertHeader('Content-Type', 'image/png');
        $getResponse->assertHeader('Content-Length', (string) strlen($originalPngBinary));

        $retrievedBytes = $getResponse->streamedContent();

        // 5. Buktikan bit-per-bit identik
        $this->assertSame($originalPngBinary, $retrievedBytes);

        // 6. Buktikan gambar benar-benar valid dan bisa di-render
        $parsedImg = imagecreatefromstring($retrievedBytes);
        $this->assertNotFalse($parsedImg, 'Binary yang dikembalikan harus berupa gambar PNG yang valid');
        $this->assertSame(10, imagesx($parsedImg), 'Lebar gambar harus 10px');
        $this->assertSame(10, imagesy($parsedImg), 'Tinggi gambar harus 10px');
        imagedestroy($parsedImg);

        // 7. Buktikan gambar bisa di-convert ke data URI base64 (seperti di PDF sidbm)
        $base64Uri = 'data:image/png;base64,'.base64_encode($retrievedBytes);
        $this->assertStringStartsWith('data:image/png;base64,iVBORw0KGgo', $base64Uri);
        $this->assertNotFalse(base64_decode(substr($base64Uri, strlen('data:image/png;base64,')), true));
    }
}
