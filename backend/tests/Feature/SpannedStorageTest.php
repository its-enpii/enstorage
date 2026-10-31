<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\FilePart;
use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\QuotaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Spanned Chunk Storage: perencanaan alokasi multi-drive + persistensi part.
 */
class SpannedStorageTest extends TestCase
{
    use RefreshDatabase;

    private function quotaWithFree(array $freeByAccountId): QuotaManager
    {
        $quota = \Mockery::mock(QuotaManager::class)->makePartial();
        $quota->shouldReceive('getQuota')->andReturnUsing(function (GoogleAccount $account) use ($freeByAccountId) {
            $free = (int) ($freeByAccountId[$account->id] ?? 0);
            $used = max(0, (int) $account->quota_total - $free);

            return [
                'total' => (int) $account->quota_total,
                'used' => $used,
                'free' => $free,
                'trashed' => 0,
                'synced_at' => now()->toIso8601String(),
            ];
        });

        return $quota;
    }

    public function test_plan_storage_allocation_uses_single_drive_when_capacity_fits(): void
    {
        $user = User::factory()->create();
        $account = GoogleAccount::factory()->create(['user_id' => $user->id]);

        $quota = $this->quotaWithFree([$account->id => 1000]);

        $plan = $quota->planStorageAllocation($user, 500);

        $this->assertCount(1, $plan);
        $this->assertSame($account->id, $plan[0]['account']->id);
        $this->assertSame(1, $plan[0]['part_number']);
        $this->assertSame(0, $plan[0]['byte_offset_start']);
        $this->assertSame(499, $plan[0]['byte_offset_end']);
        $this->assertSame(500, $plan[0]['size']);
    }

    public function test_plan_storage_allocation_spans_across_multiple_drives_greedy(): void
    {
        $user = User::factory()->create();
        // Tidak ada satu akun pun yang mampu menampung 500 byte (B hanya 400),
        // sehingga harus spanning. Urutan pembuatan sengaja terbalik supaya
        // kode harus mengurutkan sendiri: terkecil (A 200) lalu B (400).
        $accountB = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'b@example.test']);
        $accountA = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'a@example.test']);

        $quota = $this->quotaWithFree([
            $accountB->id => 400,
            $accountA->id => 200,
        ]);

        $plan = $quota->planStorageAllocation($user, 500);
        $this->assertCount(2, $plan);

        // Part 1: akun dengan sisa terkecil diisi penuh sampai 0.
        $this->assertSame($accountA->id, $plan[0]['account']->id);
        $this->assertSame(1, $plan[0]['part_number']);
        $this->assertSame(0, $plan[0]['byte_offset_start']);
        $this->assertSame(199, $plan[0]['byte_offset_end']);
        $this->assertSame(200, $plan[0]['size']);

        // Part 2: sisa byte dialokasikan ke akun berikutnya.
        $this->assertSame($accountB->id, $plan[1]['account']->id);
        $this->assertSame(2, $plan[1]['part_number']);
        $this->assertSame(200, $plan[1]['byte_offset_start']);
        $this->assertSame(499, $plan[1]['byte_offset_end']);
        $this->assertSame(300, $plan[1]['size']);
    }

    public function test_plan_storage_allocation_throws_when_pooled_capacity_insufficient(): void
    {
        $user = User::factory()->create();
        $accountA = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'a@example.test']);
        $accountB = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'b@example.test']);

        $quota = $this->quotaWithFree([
            $accountA->id => 100,
            $accountB->id => 100,
        ]);

        $this->expectException(RuntimeException::class);

        $quota->planStorageAllocation($user, 500);
    }

    public function test_spanned_file_model_and_parts_relationship(): void
    {
        $user = User::factory()->create();
        $accountA = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'a@example.test']);
        $accountB = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'b@example.test']);

        $file = File::create([
            'user_id' => $user->id,
            'google_account_id' => $accountA->id,
            'name' => 'big.bin',
            'original_name' => 'big.bin',
            'mime_type' => 'application/octet-stream',
            'size' => 500,
            'gdrive_file_id' => 'spanned_placeholder',
            'upload_status' => File::STATUS_DONE,
            'storage_driver' => 'gdrive_spanned',
            'client_key' => strtolower((string) Str::ulid()),
        ]);

        // Insert terbalik supaya urutan relasi (part_number) benar-benar diuji.
        FilePart::create([
            'file_id' => $file->id,
            'part_number' => 2,
            'google_account_id' => $accountB->id,
            'gdrive_file_id' => 'gd_part_2',
            'byte_offset_start' => 200,
            'byte_offset_end' => 499,
            'size' => 300,
        ]);
        FilePart::create([
            'file_id' => $file->id,
            'part_number' => 1,
            'google_account_id' => $accountA->id,
            'gdrive_file_id' => 'gd_part_1',
            'byte_offset_start' => 0,
            'byte_offset_end' => 199,
            'size' => 200,
        ]);

        $file->refresh();

        $this->assertTrue($file->isSpanned());
        $this->assertCount(2, $file->parts);
        $this->assertSame([1, 2], $file->parts->pluck('part_number')->all());
        $this->assertSame('gd_part_1', $file->parts->first()->gdrive_file_id);
        $this->assertSame(200, $file->parts->first()->size);
        $this->assertSame($accountA->id, $file->parts->first()->googleAccount->id);
        $this->assertSame($file->id, $file->parts->first()->file->id);
    }
}
