<?php

namespace Tests\Feature;

use App\Models\File as FileModel;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * PUT idempotency: S3 mengizinkan PUT ke key yang sama untuk mengganti
 * object. Flysystem/Laravel Storage::put memakai key yang sama tiap kali
 * menyimpan ulang, jadi gateway TIDAK boleh membuat baris File duplikat
 * (constraint uniq_files_user_client_key).
 */
class S3GatewayOverwriteTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->apiKey = (string) app(ApiKeyService::class)
            ->create((string) $this->user->id, 'overwrite-test', ['read', 'write', 'delete'])[1];
    }

    private function putObject(string $path, string $body, string $mime = 'text/plain')
    {
        return $this->call('PUT', "/api/v1/s3/public/{$path}", [], [], [], [
            'CONTENT_TYPE' => $mime,
            'HTTP_X_API_KEY' => $this->apiKey,
        ], $body);
    }

    public function test_put_same_key_twice_reuses_file_row_and_succeeds(): void
    {
        Bus::fake();

        $first = $this->putObject('uploads/logo.png', 'AAAA');
        $first->assertStatus(200);

        $second = $this->putObject('uploads/logo.png', 'BBBBBB');
        $second->assertStatus(200);

        $rows = FileModel::where('user_id', $this->user->id)
            ->where('client_key', 's3:public/uploads/logo.png')
            ->get();

        $this->assertCount(1, $rows, 'PUT ulang harus memakai baris File yang sama');
        $this->assertSame(6, (int) $rows->first()->size, 'ukuran harus ter-update ke body terbaru');
    }

    public function test_put_same_key_updates_size_and_hash(): void
    {
        Bus::fake();

        $this->putObject('uploads/doc.txt', 'short')->assertStatus(200);
        $old = FileModel::where('client_key', 's3:public/uploads/doc.txt')->firstOrFail();

        $this->putObject('uploads/doc.txt', 'much longer content')->assertStatus(200);
        $new = FileModel::where('client_key', 's3:public/uploads/doc.txt')->firstOrFail();

        $this->assertSame($old->id, $new->id, 'id baris harus dipertahankan');
        $this->assertSame(19, (int) $new->size);
        $this->assertNotSame($old->content_hash, $new->content_hash);
    }

    public function test_put_distinct_keys_create_distinct_rows(): void
    {
        Bus::fake();

        $this->putObject('uploads/a.txt', 'A')->assertStatus(200);
        $this->putObject('uploads/b.txt', 'B')->assertStatus(200);

        $this->assertSame(2, FileModel::where('user_id', $this->user->id)->count());
    }

    public function test_overwrite_keeps_share_token_stable(): void
    {
        Bus::fake();

        $this->putObject('uploads/stable.txt', 'v1')->assertStatus(200);
        $token1 = FileModel::where('client_key', 's3:public/uploads/stable.txt')->firstOrFail()->share_token;

        $this->putObject('uploads/stable.txt', 'v2')->assertStatus(200);
        $token2 = FileModel::where('client_key', 's3:public/uploads/stable.txt')->firstOrFail()->share_token;

        $this->assertSame($token1, $token2, 'share_token tidak boleh berubah saat overwrite');
    }
}
