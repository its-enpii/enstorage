<?php

namespace App\Support;

use App\Models\File as FileModel;
use App\Services\WebhookService;

/**
 * Canonical payload builders for file/folder events.
 *
 * One source of truth — both webhooks (outbound to user-configured HTTP
 * endpoints) and broadcast events (Reverb WS to web/mobile clients)
 * reuse the same shape. Callers tweak event-specific fields after the
 * base payload (e.g. `previous_folder_id` on move events).
 */
final class WebhookPayload
{
    /**
     * File upload completed — what the user receives after the upload
     * pipeline finishes. Used by:
     *   - UploadFileJob webhook `file.upload.completed`
     *   - FileUploadedBroadcast broadcast
     */
    public static function fileUploaded(FileModel $file): array
    {
        return [
            'file_id' => $file->id,
            'client_key' => $file->client_key,
            'name' => $file->name,
            'size' => (int) $file->size,
            'mime_type' => $file->mime_type,
            'folder_id' => $file->folder_id,
            'gdrive_file_id' => $file->gdrive_file_id,
            'uploaded_at' => $file->uploaded_at?->toIso8601String(),
            'share_token' => $file->share_token,
            'share_url' => $file->share_token ? WebhookService::shareUrlFor($file->share_token) : null,
            'share_preview_url' => $file->share_token ? WebhookService::shareUrlFor($file->share_token, true) : null,
            'expires_at' => null,
        ];
    }

    /**
     * File upload failed — user-visible error message attached. Used by:
     *   - UploadFileJob webhook `file.upload.failed`
     *   - FileUploadFailedBroadcast broadcast
     */
    public static function fileUploadFailed(FileModel $file, string $reason): array
    {
        return [
            'file_id' => $file->id,
            'client_key' => $file->client_key,
            'name' => $file->name,
            'folder_id' => $file->folder_id,
            'reason' => $reason,
        ];
    }

    /**
     * File updated in place (rename, star toggle, share token grant/revoke).
     * Used by:
     *   - FileUpdatedBroadcast broadcast (frontend merges via upsert)
     */
    public static function fileUpdated(FileModel $file): array
    {
        return [
            'file_id' => $file->id,
            'client_key' => $file->client_key,
            'name' => $file->name,
            'original_name' => $file->original_name,
            'is_starred' => (bool) $file->is_starred,
            'mime_type' => $file->mime_type,
            'size' => (int) $file->size,
            'folder_id' => $file->folder_id,
            'share_token' => $file->share_token,
            'share_url' => $file->share_token ? WebhookService::shareUrlFor($file->share_token) : null,
            'share_preview_url' => $file->share_token ? WebhookService::shareUrlFor($file->share_token, true) : null,
            'has_thumbnail' => $file->thumbnail !== null,
        ];
    }

    /**
     * File moved (with collision auto-rename). Used by FileMovedBroadcast.
     * Existing webhook `file.moved` in FileController::move is built
     * inline; refactoring to use this helper is a future cleanup.
     */
    public static function fileMoved(
        FileModel $file,
        ?string $previousFolderId,
        string $previousName,
        bool $renamed,
    ): array {
        return [
            'file_id' => $file->id,
            'name' => $file->name,
            'previous_name' => $renamed ? $previousName : null,
            'mime_type' => $file->mime_type,
            'size' => (int) $file->size,
            'folder_id' => $file->folder_id,
            'previous_folder_id' => $previousFolderId,
            'renamed' => $renamed,
        ];
    }
}