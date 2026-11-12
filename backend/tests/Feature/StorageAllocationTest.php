<?php

namespace Tests\Feature;

use App\Models\GoogleAccount;
use App\Models\User;
use App\Services\Google\QuotaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StorageAllocationTest extends TestCase
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

    public function test_plan_storage_allocation_uses_largest_single_drive_that_fits(): void
    {
        $user = User::factory()->create();
        $smaller = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'smaller@example.test']);
        $larger = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'larger@example.test']);

        $quota = $this->quotaWithFree([
            $smaller->id => 600,
            $larger->id => 1000,
        ]);

        $plan = $quota->planStorageAllocation($user, 500);

        $this->assertCount(1, $plan);
        $this->assertSame($larger->id, $plan[0]['account']->id);
        $this->assertSame(1, $plan[0]['part_number']);
        $this->assertSame(0, $plan[0]['byte_offset_start']);
        $this->assertSame(499, $plan[0]['byte_offset_end']);
        $this->assertSame(500, $plan[0]['size']);
    }

    public function test_plan_storage_allocation_throws_when_no_single_account_fits(): void
    {
        $user = User::factory()->create();
        $accountA = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'a@example.test']);
        $accountB = GoogleAccount::factory()->create(['user_id' => $user->id, 'email' => 'b@example.test']);

        // Total gabungan 600 byte sebenarnya cukup, tetapi file 500 byte tidak
        // boleh dipecah karena tidak ada satu akun yang mampu menampung utuh.
        $quota = $this->quotaWithFree([
            $accountA->id => 200,
            $accountB->id => 400,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tidak ada akun Google Drive yang memiliki kuota cukup.');

        $quota->planStorageAllocation($user, 500);
    }
}
