<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FileResource;
use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\GoogleAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** Kategori berkas yang dikembalikan endpoint dashboard. */
    private const CATEGORIES = ['doc', 'image', 'video', 'audio', 'app', 'archive', 'other'];

    /**
     * GET /dashboard
     * Ringkasan storage, metrik kategori berkas, dan 10 berkas terbaru.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. Quota storage — agregat dari semua akun Google aktif.
        $accounts = GoogleAccount::where('user_id', $user->id)
            ->where('is_active', true)
            ->get();
        $totalQuota = (int) $accounts->sum('quota_total');
        $usedQuota = (int) $accounts->sum('quota_used');
        $freeQuota = max(0, $totalQuota - $usedQuota);
        $percentage = $totalQuota > 0 ? round(($usedQuota / $totalQuota) * 100, 1) : 0;

        // 2. Kategori berkas — hanya file yang selesai diunggah.
        $files = FileModel::where('user_id', $user->id)
            ->where('upload_status', FileModel::STATUS_DONE)
            ->get(['id', 'name', 'original_name', 'mime_type', 'size']);

        $buckets = array_fill_keys(self::CATEGORIES, ['count' => 0, 'size' => 0]);
        foreach ($files as $file) {
            $key = self::classify($file->mime_type, $file->name ?: $file->original_name);
            $buckets[$key]['count']++;
            $buckets[$key]['size'] += (int) $file->size;
        }

        $categories = [];
        foreach (self::CATEGORIES as $key) {
            $categories[] = [
                'type' => $key,
                'count' => $buckets[$key]['count'],
                'size' => $buckets[$key]['size'],
            ];
        }

        // 3. Berkas terbaru.
        $recent = FileModel::where('user_id', $user->id)
            ->where('upload_status', FileModel::STATUS_DONE)
            ->with(['thumbnail:id,file_id', 'folder:id,name,path'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return $this->ok([
            'storage' => [
                'total' => $totalQuota,
                'used' => $usedQuota,
                'free' => $freeQuota,
                'percentage' => $percentage,
                'accounts_count' => $accounts->count(),
            ],
            'categories' => $categories,
            'recent_files' => FileResource::collection($recent)->resolve($request),
            'summary' => [
                'files_count' => $files->count(),
                'files_size' => (int) $files->sum('size'),
                'folders_count' => Folder::where('user_id', $user->id)->count(),
                'accounts_count' => $accounts->count(),
            ],
        ], __('Dashboard berhasil dimuat.'));
    }

    /**
     * Klasifikasikan berkas ke salah satu kategori dashboard.
     */
    public static function classify(?string $mime, ?string $name): string
    {
        $mime = strtolower((string) $mime);
        $name = strtolower((string) $name);

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        if (preg_match('/\.(apk|exe|dmg|deb|rpm)$/', $name)
            || in_array($mime, ['application/vnd.android.package-archive', 'application/x-msdownload', 'application/x-executable'], true)) {
            return 'app';
        }
        if (preg_match('/\.(zip|rar|7z|tar|gz)$/', $name)
            || in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/x-7z-compressed', 'application/x-rar-compressed', 'application/gzip', 'application/x-tar'], true)) {
            return 'archive';
        }
        if ($mime === 'application/pdf'
            || str_contains($mime, 'word')
            || str_contains($mime, 'excel')
            || str_contains($mime, 'spreadsheet')
            || str_contains($mime, 'presentation')
            || str_contains($mime, 'powerpoint')
            || str_starts_with($mime, 'text/')
            || preg_match('/\.(docx?|xlsx?|pptx?|txt|md|csv)$/', $name)) {
            return 'doc';
        }

        return 'other';
    }
}
