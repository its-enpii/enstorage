<?php

namespace App\Jobs;

use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\ShareLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Auto-expire share links yang expires_at-nya sudah lewat.
 *
 * Hard delete row share_links (vs soft delete revoked_at) — sesuai
 * keputusan produk: saat auto-expire, link dianggap tidak ada lagi.
 * Manual revoke (ShareLinkController::destroy) tetap pakai soft delete
 * via revoked_at untuk audit trail.
 *
 * Side effect: kalau token yang expire adalah token yang di-mirror ke
 * files.share_token / folders.share_token, kosongkan legacy column
 * supaya resolusi viewByToken return 404, bukan 410. Token lain (kalau
 * file/folder punya multiple pivot rows dengan token beda) tidak
 * disentuh.
 *
 * Broadcast per-row ke ShareLinksUpdatedBroadcast tetap dilakukan
 * (action='revoked', reason='expired') supaya frontend list update.
 * Kalau mobile/web listen channel user-*, mereka tahu link sudah
 * tidak valid.
 */
class ExpireShareLinksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function handle(): void
    {
        $now = now();

        // 1) Collect semua row yang expire, grouped by shareable.
        $expired = ShareLink::whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->get(['id', 'user_id', 'shareable_type', 'shareable_id', 'token']);

        if ($expired->isEmpty()) {
            return;
        }

        // 2) Bulk delete — efisien untuk dataset besar.
        $ids = $expired->pluck('id')->all();
        ShareLink::whereIn('id', $ids)->delete();

        // 3) Clear legacy share_token kalau token-nya match. Hanya
        //    untuk file/folder yang share_token legacy == token pivot
        //    yang baru di-delete. Multi-pivot/shareable lain tidak
        //    tersentuh.
        foreach ($expired->groupBy(fn ($l) => $l->shareable_type) as $type => $rows) {
            $bySubject = $rows->groupBy('shareable_id');
            foreach ($bySubject as $subjectId => $subjectRows) {
                $tokensForSubject = $subjectRows->pluck('token')->unique()->all();

                if ($type === FileModel::class) {
                    FileModel::whereIn('id', (array) $subjectId)
                        ->whereIn('share_token', $tokensForSubject)
                        ->update(['share_token' => null]);
                } elseif ($type === Folder::class) {
                    Folder::whereIn('id', (array) $subjectId)
                        ->whereIn('share_token', $tokensForSubject)
                        ->update(['share_token' => null]);
                }
            }
        }

        // 4) Broadcast per-row untuk frontend realtime update.
        foreach ($expired as $link) {
            try {
                \App\Events\ShareLinksUpdatedBroadcast::dispatch($link, 'revoked', 'expired');
            } catch (\Throwable $e) {
                Log::warning('ExpireShareLinksJob: broadcast gagal', [
                    'share_link_id' => $link->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("ExpireShareLinksJob: deleted {$expired->count()} expired share link(s)");
    }
}