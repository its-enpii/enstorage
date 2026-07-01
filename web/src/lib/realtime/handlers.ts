/**
 * Pure event-mapper for Reverb broadcasts.
 *
 * Each WS event payload (see backend `app/Support/WebhookPayload.php`)
 * is mapped onto the FilesStoreContext mutations that already exist in
 * `src/lib/filesStore.tsx` (upsertFile, removeFile, etc.). Keeping the
 * mapping pure lets us unit-test each branch in isolation and reuse the
 * same logic from a future cross-tab BroadcastChannel bridge.
 */

import type { FileItem, Folder as FolderType } from '@/lib/api';

/**
 * Subset of FilesStoreContext mutators we need. Declared here as a type
 * to avoid a circular import with `filesStore.tsx` (which imports
 * from `api.ts`).
 */
export type StoreMutators = {
  upsertFile: (f: FileItem) => void;
  removeFile: (id: string) => void;
  upsertFolder: (f: FolderType) => void;
  removeFolder: (id: string) => void;
  updateFolderCounts: (id: string, deltaFiles: number, deltaSize: number) => void;
  revalidate: () => Promise<void>;
};

export type RealtimeEvent =
  | { type: 'file.uploaded'; file: FileItem }
  | { type: 'file.upload_failed'; fileId: string; folderId: string | null; uploadStatus: string; reason: string }
  | { type: 'file.moved'; file: FileItem; previousFolderId: string | null; renamed: boolean }
  | { type: 'file.deleted'; fileId: string; folderId: string | null }
  | { type: 'file.updated'; file: FileItem }
  | { type: 'folder.created'; folder: FolderType }
  | { type: 'folder.deleted'; folderId: string; parentId: string | null }
  | { type: 'folder.renamed'; folder: FolderType; previousName: string }
  | { type: 'folder.moved'; folder: FolderType; previousParentId: string | null };

export type ApplyContext = {
  /** FilesStoreContext for the current view (null when outside /files). */
  store: StoreMutators | null;
  /** The folder_id currently shown in URL (null = root). */
  currentFolderId: string | null;
  /**
   * Folder IDs visible as cards in the current view's `folders` array.
   * Used by `updateFolderCounts` no-op detection — folders outside the
   * current view don't need count adjustments (server-side count wins
   * on next revalidate).
   */
  visibleFolderIds: Set<string>;
};

/**
 * Map a single broadcast event into store mutations on `ctx.store`.
 * Returns true when the event mutated state, false on no-op.
 */
export function applyEvent(e: RealtimeEvent, ctx: ApplyContext): boolean {
  const { store, currentFolderId, visibleFolderIds } = ctx;
  if (!store) return false;

  switch (e.type) {
    case 'file.uploaded': {
      // Only insert when the broadcast's folder matches the view (or view
      // is root and the upload landed at root). The Reverb channel split
      // already filters by folder_id, but `root` subscribers also receive
      // all folder uploads — gate at the view layer to avoid noise.
      if (matchesView(e.file.folder_id, currentFolderId)) {
        store.upsertFile(e.file);
        return true;
      }
      return false;
    }

    case 'file.upload_failed': {
      // Mark existing pending row as failed (if visible). Backend fires
      // this per folder_id so subscribers in non-current folders won't
      // see it; only the file's owning folder receives it.
      if (matchesView(e.folderId, currentFolderId)) {
        // Remove from optimistic list — UI will refetch to see the final
        // state. Best-effort: if the row is absent, the next revalidate
        // picks it up correctly.
        store.removeFile(e.fileId);
        return true;
      }
      return false;
    }

    case 'file.moved': {
      const movedInto = e.file.folder_id ?? null;
      const movedFrom = e.previousFolderId ?? null;
      const inCurrentView = matchesView(movedInto, currentFolderId);
      const wasInCurrentView = matchesView(movedFrom, currentFolderId);

      // Remove from current view if the file moved OUT.
      if (wasInCurrentView && !inCurrentView) {
        store.removeFile(e.file.id);
      }

      // Insert into current view if the file moved IN (or stayed).
      if (inCurrentView) {
        store.upsertFile(e.file);
      }

      // Adjust folder card counts. `updateFolderCounts` itself is a no-op
      // when the folder id is absent from `folders` array, so the Set is
      // informational only — we don't strictly need it. Skipping the set
      // check keeps the function cheap.
      if (wasInCurrentView && movedFrom && visibleFolderIds.has(movedFrom)) {
        store.updateFolderCounts(movedFrom, -1, -((e.file as FileItem).size ?? 0));
      }
      if (inCurrentView && movedInto && movedFrom !== movedInto && visibleFolderIds.has(movedInto)) {
        store.updateFolderCounts(movedInto, +1, +((e.file as FileItem).size ?? 0));
      }
      return inCurrentView || wasInCurrentView;
    }

    case 'file.deleted': {
      if (matchesView(e.folderId, currentFolderId)) {
        store.removeFile(e.fileId);
        // Folder card count — same no-op behavior as above.
        if (e.folderId && visibleFolderIds.has(e.folderId)) {
          store.updateFolderCounts(e.folderId, -1, 0);
        }
        return true;
      }
      return false;
    }

    case 'file.updated': {
      // File broadcasts are scoped to `client.{ck}.folder.{fid}` so we
      // only receive this for the folder we're viewing.
      if (matchesView(e.file.folder_id ?? null, currentFolderId)) {
        store.upsertFile(e.file);
        return true;
      }
      return false;
    }

    case 'folder.created': {
      if (matchesView(e.folder.parent_id ?? null, currentFolderId)) {
        store.upsertFolder(e.folder);
        return true;
      }
      return false;
    }

    case 'folder.deleted': {
      // A folder gone — its parent loses a child card. Children of the
      // deleted folder are dropped by `file.moved` (cascade) broadcasts.
      if (matchesView(e.parentId ?? null, currentFolderId)) {
        store.removeFolder(e.folderId);
        return true;
      }
      return false;
    }

    case 'folder.renamed':
    case 'folder.moved': {
      // Treat rename + move identically — both end with `folder` reflecting
      // the post-state. If folder's parent changed, old parent's view
      // removes it; new parent's view inserts it. We always upsert when
      // it's in the current view; remove if it's in old parent.
      if (e.type === 'folder.moved' && e.previousParentId !== undefined) {
        if (matchesView(e.previousParentId, currentFolderId)) {
          store.removeFolder(e.folder.id);
        }
      }
      if (matchesView(e.folder.parent_id ?? null, currentFolderId)) {
        store.upsertFolder(e.folder);
        return true;
      }
      return false;
    }

    default: {
      // Exhaustiveness check — TypeScript will error here if a new
      // RealtimeEvent variant is added without a handler.
      const _exhaustive: never = e;
      void _exhaustive;
      return false;
    }
  }
}

/**
 * Mismatch rule:
 * - `event.folderId` matches `currentFolderId` (including both null = root)
 * - `currentFolderId === null` (root view) accepts ANY folder_id so the
 *   whole-user channel notifications show up consistently.
 */
function matchesView(eventFolderId: string | null, currentFolderId: string | null): boolean {
  if (currentFolderId === null) return true; // root view = "show all folders"
  return eventFolderId === currentFolderId;
}

/**
 * Coerce a raw Pusher payload into a typed RealtimeEvent. Throws on
 * unrecognized shapes — caller logs + drops.
 *
 * Backend broadcasts come through Pusher as:
 *   { event: 'App\\Events\\FileUploadedBroadcast', data: { file_id, ... }, channel: 'private-client....' }
 *
 * The `event` field is the FQCN and tells us which variant to map to.
 */
export function parseRealtimePayload(
  rawEventName: string,
  rawData: unknown,
): RealtimeEvent | null {
  const data = (rawData ?? {}) as Record<string, unknown>;

  if (rawEventName === 'App\\Events\\FileUploadedBroadcast') {
    const file = data as unknown as FileItem;
    if (!file?.id) return null;
    return { type: 'file.uploaded', file };
  }
  if (rawEventName === 'App\\Events\\FileUploadFailedBroadcast') {
    return {
      type: 'file.upload_failed',
      fileId: String(data.file_id ?? ''),
      folderId: (data.folder_id as string | null) ?? null,
      uploadStatus: String(data.upload_status ?? 'failed'),
      reason: String(data.reason ?? ''),
    };
  }
  if (rawEventName === 'App\\Events\\FileMovedBroadcast') {
    const file = data as unknown as FileItem;
    if (!file?.id) return null;
    return {
      type: 'file.moved',
      file,
      previousFolderId: (data.previous_folder_id as string | null) ?? null,
      renamed: Boolean(data.renamed),
    };
  }
  if (rawEventName === 'App\\Events\\FileDeletedBroadcast') {
    return {
      type: 'file.deleted',
      fileId: String(data.file_id ?? ''),
      folderId: (data.folder_id as string | null) ?? null,
    };
  }
  if (rawEventName === 'App\\Events\\FileUpdatedBroadcast') {
    const file = data as unknown as FileItem;
    if (!file?.id) return null;
    return { type: 'file.updated', file };
  }
  if (rawEventName === 'App\\Events\\FolderCreatedBroadcast') {
    const folder = data as unknown as FolderType;
    if (!folder?.id) return null;
    return { type: 'folder.created', folder };
  }
  if (rawEventName === 'App\\Events\\FolderDeletedBroadcast') {
    return {
      type: 'folder.deleted',
      folderId: String(data.folder_id ?? ''),
      parentId: (data.parent_id as string | null) ?? null,
    };
  }
  if (rawEventName === 'App\\Events\\FolderRenamedBroadcast') {
    const folder = data as unknown as FolderType;
    if (!folder?.id) return null;
    return { type: 'folder.renamed', folder, previousName: String(data.previous_name ?? folder.name) };
  }
  if (rawEventName === 'App\\Events\\FolderMovedBroadcast') {
    const folder = data as unknown as FolderType;
    if (!folder?.id) return null;
    return { type: 'folder.moved', folder, previousParentId: (data.previous_parent_id as string | null) ?? null };
  }
  return null;
}
