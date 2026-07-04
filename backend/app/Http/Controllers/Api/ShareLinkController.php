<?php

namespace App\Http\Controllers\Api;

use App\Events\ShareLinksUpdatedBroadcast;
use App\Http\Controllers\Controller;
use App\Http\Resources\ShareLinkResource;
use App\Models\File as FileModel;
use App\Models\Folder;
use App\Models\ShareLink;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShareLinkController extends Controller
{
    public function __construct(private readonly WebhookService $webhooks) {}

    /**
     * POST /files/{id}/share-links — bikin share link baru untuk file.
     */
    public function storeForFile(Request $request, string $fileId): JsonResponse
    {
        $file = $this->findOwnedFile($request, $fileId);
        if (! $file) {
            return $this->fail(__('File tidak ditemukan.'), 404);
        }

        return $this->store($request, FileModel::class, $file->id, 'file.shared');
    }

    /**
     * POST /folders/{id}/share-links — bikin share link baru untuk folder.
     */
    public function storeForFolder(Request $request, string $folderId): JsonResponse
    {
        $folder = $this->findOwnedFolder($request, $folderId);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        return $this->store($request, Folder::class, $folder->id, 'folder.shared');
    }

    /**
     * GET /files/{id}/share-links — list share link aktif milik file.
     */
    public function indexForFile(Request $request, string $fileId): JsonResponse
    {
        $file = $this->findOwnedFile($request, $fileId);
        if (! $file) {
            return $this->fail(__('File tidak ditemukan.'), 404);
        }

        return $this->index($request, FileModel::class, $file->id);
    }

    /**
     * GET /folders/{id}/share-links — list share link aktif milik folder.
     */
    public function indexForFolder(Request $request, string $folderId): JsonResponse
    {
        $folder = $this->findOwnedFolder($request, $folderId);
        if (! $folder) {
            return $this->fail(__('Folder tidak ditemukan.'), 404);
        }

        return $this->index($request, Folder::class, $folder->id);
    }

    /**
     * DELETE /share-links/{id} — manual revoke. Owner saja.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $link = ShareLink::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if (! $link) {
            return $this->fail(__('Share link tidak ditemukan.'), 404);
        }

        if ($link->revoked_at !== null) {
            return $this->fail(__('Share link sudah di-revoke.'), 409);
        }

        $link->revoked_at = now();
        $link->save();

        // Realtime + webhook — UI list refresh, webhook fanout.
        ShareLinksUpdatedBroadcast::dispatch($link, 'revoked', 'manual');
        $this->dispatchWebhook($link, 'revoked', 'manual');

        return $this->ok([
            'id' => $link->id,
            'revoked_at' => $link->revoked_at?->toIso8601String(),
        ], __('Share link di-revoke.'));
    }

    // ─── Internal helpers ───────────────────────────────────────────

    private function store(
        Request $request,
        string $shareableType,
        string $shareableId,
        string $webhookEvent,
    ): JsonResponse {
        $payload = $this->validateStore($request);

        $link = ShareLink::create([
            'user_id' => $request->user()->id,
            'shareable_type' => $shareableType,
            'shareable_id' => $shareableId,
            'token' => bin2hex(random_bytes(16)),
            'expires_at' => $payload['expires_at'] ?? null,
            'max_views' => $payload['max_views'] ?? null,
        ]);

        ShareLinksUpdatedBroadcast::dispatch($link, 'created', null);
        $this->dispatchWebhook($link, 'created', null);

        return $this->created(
            (new ShareLinkResource($link))->resolve(),
            __('Share link berhasil dibuat.'),
        );
    }

    private function index(Request $request, string $shareableType, string $shareableId): JsonResponse
    {
        $links = ShareLink::where('user_id', $request->user()->id)
            ->where('shareable_type', $shareableType)
            ->where('shareable_id', $shareableId)
            ->active()
            ->orderByDesc('created_at')
            ->get();

        return $this->ok(
            ShareLinkResource::collection($links)->resolve(),
            __('Daftar share link aktif.'),
        );
    }

    private function validateStore(Request $request): array
    {
        try {
            return $request->validate([
                'expires_at' => 'nullable|date|after:now',
                'max_views' => 'nullable|integer|min:1|max:10000',
            ]);
        } catch (ValidationException $e) {
            // Translate 'after:now' jadi pesan yang lebih jelas.
            $errors = $e->errors();
            if (isset($errors['expires_at'])) {
                $errors['expires_at'] = [__('Tanggal kadaluarsa harus di masa depan.')];
            }
            throw ValidationException::withMessages($errors);
        }
    }

    private function findOwnedFile(Request $request, string $id): ?FileModel
    {
        return FileModel::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
    }

    private function findOwnedFolder(Request $request, string $id): ?Folder
    {
        return Folder::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
    }

    private function dispatchWebhook(ShareLink $link, string $action, ?string $reason): void
    {
        // Pakai naming events baru (file.share_link.* / folder.share_link.*)
        // — lebih granular dari file.shared/folder.shared legacy yang
        // mengekspos share_token. Webhook::EVENTS belum di-update di PR
        // ini; tambahkan bila butuh fanout ke webhook subscriber.
        $subject = $link->shareable_type === FileModel::class ? 'file' : 'folder';
        $this->webhooks->dispatch(
            $link->user_id,
            "{$subject}.share_link.{$action}",
            [
                'share_link_id' => $link->id,
                "{$subject}_id" => $link->shareable_id,
                'token' => $link->token,
                'share_url' => WebhookService::shareUrlFor($link->token),
                'expires_at' => $link->expires_at?->toIso8601String(),
                'max_views' => $link->max_views,
                'views_count' => (int) $link->views_count,
                'revoked_at' => $link->revoked_at?->toIso8601String(),
                'reason' => $reason,
            ],
        );
    }
}