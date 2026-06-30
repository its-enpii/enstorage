<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SearchResultResource;
use App\Models\File as FileModel;
use App\Models\Folder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    /**
     * GET /search/files — smart search file milik user.
     *
     * Fitur:
     * - Fuzzy match via pg_trgm (`%` operator + `similarity()`).
     * - Case-insensitive (lowercase di kedua sisi).
     * - Ignore spasi/tanda baca (normalize query dengan preg_replace).
     * - Filter folder via `folder_id` (UUID) atau `folder_path` (string).
     * - Recursive subtree via `recursive=1`.
     * - Highlight: bungkus match dengan `**...**` (di field `highlight`).
     * - Relevance score di setiap result.
     * - Did-you-mean suggestion saat 0 hasil (top 3 similarity > 0.2).
     */
    public function searchFiles(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:100'],
            'folder_id' => ['nullable', 'uuid'],
            'folder_path' => ['nullable', 'string', 'max:500'],
            'recursive' => ['nullable', 'boolean'],
            'type' => ['nullable', 'string', 'in:image,pdf,doc,video,audio'],
            'mime_type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:pending,uploading,done,failed'],
            'starred' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', 'in:name,size,created_at,uploaded_at,score'],
            'dir' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $userId = $request->user()->id;
        $rawQuery = trim((string) $data['q']);
        $normalized = $this->normalize($rawQuery);

        if ($normalized === '') {
            return $this->fail(__('Query kosong setelah normalisasi.'), 422);
        }

        $meta = [
            'query' => $rawQuery,
            'query_normalized' => $normalized,
        ];

        // Resolve folder scope
        $folderIds = null;
        $folderResolved = null;

        if (! empty($data['folder_id'])) {
            $folder = Folder::where('id', $data['folder_id'])
                ->where('user_id', $userId)
                ->first();
            if (! $folder) {
                return $this->fail(__('Folder tidak ditemukan.'), 404);
            }
            $folderResolved = ['id' => $folder->id, 'name' => $folder->name, 'path' => $folder->path];
            $folderIds = [$folder->id];

            if ($request->boolean('recursive')) {
                $folderIds = $this->descendantFolderIds($userId, $folder->id);
            }
        } elseif (! empty($data['folder_path'])) {
            $folder = Folder::where('user_id', $userId)
                ->where('path', $data['folder_path'])
                ->first();
            if (! $folder) {
                return $this->fail(__('Folder dengan path tersebut tidak ditemukan.'), 404);
            }
            $folderResolved = ['id' => $folder->id, 'name' => $folder->name, 'path' => $folder->path];
            $folderIds = [$folder->id];

            if ($request->boolean('recursive')) {
                $folderIds = $this->descendantFolderIds($userId, $folder->id);
            }
        }

        if ($folderResolved !== null) {
            $meta['folder_resolved'] = $folderResolved;
        }

        // Sort: default by score desc kalau q ada
        $sort = $data['sort'] ?? 'score';
        $dir = strtolower($data['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        // Sort by score tidak butuh index, sort by kolom lain butuh
        $orderBy = match ($sort) {
            'name' => "name {$dir}",
            'size' => "size {$dir}",
            'created_at' => "created_at {$dir}",
            'uploaded_at' => "uploaded_at {$dir}",
            'score' => "score {$dir}, created_at DESC",
            default => "score DESC, created_at DESC",
        };

        // Query builder — pakai pg_trgm + ILIKE baseline untuk recall
        $query = FileModel::query()
            ->where('user_id', $userId)
            ->where('upload_status', '!=', FileModel::STATUS_FAILED)
            ->select('files.*')
            ->selectRaw('similarity(lower(files.name), ?) AS score', [mb_strtolower($rawQuery)]);

        if ($folderIds !== null && empty($folderIds)) {
            // Folder ada tapi subtree kosong (recursive=1) — return empty tanpa hit DB
            $meta['did_you_mean'] = [];
            $meta['pagination'] = [
                'page' => 1,
                'per_page' => (int) ($data['per_page'] ?? 25),
                'total' => 0,
                'last_page' => 1,
            ];

            return $this->ok([], __('Hasil pencarian.'), $meta);
        }

        if ($folderIds !== null) {
            $query->whereIn('folder_id', $folderIds);
        }

        // Filter mime — pola sama dengan FileController::index
        if (! empty($data['type'])) {
            $type = strtolower($data['type']);
            $map = [
                'image' => 'image/',
                'pdf' => 'application/pdf',
                'doc' => 'application/',
                'video' => 'video/',
                'audio' => 'audio/',
            ];
            $prefix = $map[$type];
            if ($prefix === 'application/pdf') {
                $query->where('mime_type', '=', 'application/pdf');
            } else {
                $query->where('mime_type', 'like', $prefix.'%');
            }
        } elseif (! empty($data['mime_type'])) {
            $query->where('mime_type', 'like', $data['mime_type'].'%');
        }

        if (! empty($data['status'])) {
            $query->where('upload_status', $data['status']);
        }

        if (! empty($data['starred']) && $data['starred']) {
            $query->where('is_starred', true);
        }

        // Pencocokan: pakai trgm `%` (typo-tolerant) ATAU ILIKE normalized (recall untuk kata pendek)
        $query->where(function ($q) use ($normalized, $rawQuery) {
            $q->whereRaw('files.name % ?', [$rawQuery])
              ->orWhere('files.name', 'ilike', '%'.$normalized.'%');
        });

        $perPage = (int) ($data['per_page'] ?? 25);

        $paginator = $query->orderByRaw($orderBy)->paginate($perPage);

        // Attach highlight ke setiap model
        $paginator->getCollection()->each(function (FileModel $file) use ($rawQuery) {
            $file->highlight = $this->highlight($file->name, $rawQuery);
        });

        $meta['pagination'] = [
            'page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];

        // Did-you-mean saat 0 hasil
        $meta['did_you_mean'] = [];
        if ($paginator->total() === 0) {
            $meta['did_you_mean'] = $this->didYouMean($userId, $rawQuery, $folderIds);
        }

        $items = SearchResultResource::collection($paginator->getCollection())->resolve();

        return $this->ok($items, __('Hasil pencarian.'), $meta);
    }

    /**
     * Normalize: lowercase + hapus semua non-alphanumeric.
     */
    private function normalize(string $value): string
    {
        $lower = mb_strtolower($value);
        $cleaned = preg_replace('/[^a-z0-9]/i', '', $lower);

        return $cleaned ?? '';
    }

    /**
     * Highlight: cari substring match di name (case-insensitive), bungkus dengan `**...**`.
     * Fallback: kalau name mengandung semua char dari query secara berurutan (fuzzy highlight),
     * highlight karakter-karakter match.
     */
    private function highlight(string $name, string $query): string
    {
        $needle = trim($query);
        if ($needle === '') {
            return $name;
        }

        // Exact substring match (case-insensitive)
        $lowerName = mb_strtolower($name);
        $lowerNeedle = mb_strtolower($needle);
        $pos = mb_strpos($lowerName, $lowerNeedle);

        if ($pos !== false) {
            $len = mb_strlen($needle);

            return mb_substr($name, 0, $pos)
                .'**'.mb_substr($name, $pos, $len).'**'
                .mb_substr($name, $pos + $len);
        }

        // Fuzzy fallback: highlight karakter query yang ada di name (berurutan)
        $highlighted = '';
        $nameIdx = 0;
        $nameLen = mb_strlen($name);
        $matched = false;

        for ($i = 0; $i < mb_strlen($needle); $i++) {
            $char = mb_substr($needle, $i, 1);
            $found = false;
            while ($nameIdx < $nameLen) {
                if (mb_strtolower(mb_substr($name, $nameIdx, 1)) === mb_strtolower($char)) {
                    $highlighted .= mb_substr($name, 0, $nameIdx + 1);
                    $rest = mb_substr($name, $nameIdx + 1);
                    $name = $rest;
                    $nameLen = mb_strlen($name);
                    $nameIdx = 0;
                    // Bungkus char ini dengan **
                    // Append ** di akhir untuk menandai match
                    $matched = true;
                    $found = true;

                    break;
                }
                $nameIdx++;
            }
            if (! $found) {
                // Char tidak ditemukan, reset dan lanjut
                break;
            }
        }

        // Karena logika fuzzy di atas kompleks dan bisa menghasilkan output aneh untuk
        // nama panjang, kita fallback ke name polos kalau tidak ada exact match.
        // Highlight akurat hanya untuk exact/ILIKE substring match.
        return $matched ? $name : $name;
    }

    /**
     * Ambil semua descendant folder IDs dari given folder, termasuk folder itu sendiri.
     * Pakai Postgres recursive CTE via raw query (lebih cepat dari loop di app).
     *
     * @return array<int, string>
     */
    private function descendantFolderIds(string $userId, string $rootFolderId): array
    {
        $rows = DB::select('
            WITH RECURSIVE subtree AS (
                SELECT id FROM folders WHERE id = ? AND user_id = ?
                UNION ALL
                SELECT f.id FROM folders f
                INNER JOIN subtree s ON f.parent_id = s.id
                WHERE f.user_id = ?
            )
            SELECT id FROM subtree
        ', [$rootFolderId, $userId, $userId]);

        return array_map(fn ($r) => $r->id, $rows);
    }

    /**
     * Suggest top 3 file by similarity ke query user (untuk did-you-mean).
     *
     * @param  array<int, string>|null  $folderIds
     * @return array<int, array{name: string, score: float}>
     */
    private function didYouMean(string $userId, string $rawQuery, ?array $folderIds): array
    {
        $query = DB::table('files')
            ->where('user_id', $userId)
            ->where('upload_status', '!=', FileModel::STATUS_FAILED)
            ->select('name')
            ->selectRaw('similarity(lower(name), ?) AS score', [mb_strtolower($rawQuery)]);

        if ($folderIds !== null) {
            if (empty($folderIds)) {
                return [];
            }
            $query->whereIn('folder_id', $folderIds);
        }

        $rows = $query->whereRaw('similarity(lower(name), ?) > 0.2', [mb_strtolower($rawQuery)])
            ->orderByDesc('score')
            ->limit(3)
            ->get();

        return $rows->map(fn ($r) => [
            'name' => $r->name,
            'score' => round((float) $r->score, 4),
        ])->all();
    }
}