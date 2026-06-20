<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GoogleAccount;
use App\Services\Google\QuotaManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class StorageController extends Controller
{
    public function __construct(private readonly QuotaManager $quota) {}

    /**
     * GET /storage/summary
     * Total/used/free agregat dari SEMUA akun Google milik user yang login.
     */
    public function summary(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $accounts = GoogleAccount::where('user_id', $userId)
            ->where('is_active', true)
            ->get();

        $breakdown = [];
        $total = 0;
        $used = 0;
        $errored = 0;

        foreach ($accounts as $account) {
            try {
                $q = $this->quota->getQuota($account);
                $total += $q['total'];
                $used += $q['used'];
                $breakdown[] = [
                    'account_id' => $account->id,
                    'label' => $account->label,
                    'email' => $account->email,
                    'quota' => $q,
                ];
            } catch (Throwable $e) {
                $errored++;
                $breakdown[] = [
                    'account_id' => $account->id,
                    'label' => $account->label,
                    'email' => $account->email,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $this->ok([
            'accounts_count' => $accounts->count(),
            'accounts_errored' => $errored,
            'total' => $total,
            'used' => $used,
            'free' => max(0, $total - $used),
            'breakdown' => $breakdown,
        ], __('Ringkasan storage berhasil dimuat.'));
    }
}
