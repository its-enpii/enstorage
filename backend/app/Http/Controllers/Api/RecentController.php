<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecentResource;
use App\Models\File as FileModel;
use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /recent — mixed list of root-level folders & files, sorted by
 * `updated_at` desc (then `id` desc as a stable tiebreaker), cursor-paginated.
 *
 * Root-only: a folder is "root" when `parent_id IS NULL`; a file is "root"
 * when `folder_id IS NULL`. Items inside subfolders are intentionally
 * excluded — use the per-folder endpoints for that.
 *
 * Cursor format: base64-encoded JSON `{"u": "<iso updated_at>", "i": "<id>"}`.
 * Items with `(updated_at, id)` strictly less than the cursor are skipped
 * (i.e. we return the next page after that point).
 */
class RecentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $limit = min(100, max(1, (int) $request->query('limit', 30)));
        $cursor = $this->decodeCursor($request->query('cursor'));

        // Fetch limit+1 of each so we know if there's a next page.
        $foldersQuery = Folder::query()
            ->where('user_id', $userId)
            ->whereNull('parent_id')
            ->withCount(['files', 'children'])
            ->withSum('files', 'size')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $filesQuery = FileModel::query()
            ->where('user_id', $userId)
            ->whereNull('folder_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($cursor !== null) {
            // (updated_at, id) < (cursor.u, cursor.i) — composite comparison.
            $foldersQuery->where(function ($q) use ($cursor) {
                $q->where('updated_at', '<', $cursor['u'])
                    ->orWhere(function ($q2) use ($cursor) {
                        $q2->where('updated_at', '=', $cursor['u'])
                            ->where('id', '<', $cursor['i']);
                    });
            });
            $filesQuery->where(function ($q) use ($cursor) {
                $q->where('updated_at', '<', $cursor['u'])
                    ->orWhere(function ($q2) use ($cursor) {
                        $q2->where('updated_at', '=', $cursor['u'])
                            ->where('id', '<', $cursor['i']);
                    });
            });
        }

        $folders = $foldersQuery->limit($limit + 1)->get();
        $files = $filesQuery->limit($limit + 1)->get();

        // Merge with stable ordering: updated_at desc, then id desc, then
        // folders before files (deterministic tiebreak for same timestamp).
        $merged = collect();
        foreach ($folders as $f) {
            $merged->push(['type' => 'folder', 'data' => $f, 'sort_u' => $f->updated_at, 'sort_i' => $f->id]);
        }
        foreach ($files as $f) {
            $merged->push(['type' => 'file', 'data' => $f, 'sort_u' => $f->updated_at, 'sort_i' => $f->id]);
        }
        $merged = $merged
            ->sortBy([
                ['sort_u', 'desc'],
                ['sort_i', 'desc'],
            ])
            ->values();

        $hasMore = $merged->count() > $limit;
        $page = $merged->take($limit);

        $nextCursor = null;
        if ($hasMore && $page->isNotEmpty()) {
            $last = $page->last();
            $nextCursor = $this->encodeCursor($last['sort_u'], $last['sort_i']);
        }

        $payload = $page->map(fn ($row) => new RecentResource($row))->all();

        return $this->ok([
            'items' => $payload,
            'next_cursor' => $nextCursor,
        ], __('Daftar terbaru.'));
    }

    /**
     * Decode a base64 JSON cursor. Returns null on any failure so a bad
     * cursor degrades gracefully to "start from the beginning".
     *
     * @return array{u: string, i: string}|null
     */
    private function decodeCursor(?string $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $json = base64_decode($raw, true);
        if ($json === false) {
            return null;
        }
        $obj = json_decode($json, true);
        if (! is_array($obj) || ! isset($obj['u'], $obj['i']) || ! is_string($obj['u']) || ! is_string($obj['i'])) {
            return null;
        }
        return ['u' => $obj['u'], 'i' => $obj['i']];
    }

    private function encodeCursor(\DateTimeInterface|string $updatedAt, string $id): string
    {
        $u = $updatedAt instanceof \DateTimeInterface ? $updatedAt->format(\DateTimeInterface::ATOM) : (string) $updatedAt;
        return base64_encode(json_encode(['u' => $u, 'i' => $id], JSON_UNESCAPED_SLASHES));
    }
}
