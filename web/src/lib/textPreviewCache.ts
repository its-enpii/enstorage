// In-memory cache for text preview payloads (code / plain text viewers).
// Keyed by file id, TTL-bounded and capped so a long browsing session can
// re-open the same file without a full refetch. Failures are never cached.

export const TEXT_PREVIEW_TTL_MS = 10 * 60_000;
export const TEXT_PREVIEW_MAX_ENTRIES = 50;

type Entry = {
  content: string;
  ts: number;
};

const store = new Map<string, Entry>();

/** Return cached content for a file, or null on miss/expiry. */
export function getCachedText(fileId: string, now: number = Date.now()): string | null {
  const entry = store.get(fileId);
  if (!entry) return null;
  if (now - entry.ts > TEXT_PREVIEW_TTL_MS) {
    store.delete(fileId);
    return null;
  }
  return entry.content;
}

/** Store content for a file, evicting the oldest entry beyond the cap. */
export function setCachedText(fileId: string, content: string, now: number = Date.now()): void {
  store.delete(fileId);
  store.set(fileId, { content, ts: now });
  while (store.size > TEXT_PREVIEW_MAX_ENTRIES) {
    const oldest = store.keys().next();
    if (oldest.done) break;
    store.delete(oldest.value);
  }
}

/** Drop one file's cached content (e.g. after a failed refetch). */
export function invalidateCachedText(fileId: string): void {
  store.delete(fileId);
}

/** Test helper — clear the whole cache. */
export function clearTextPreviewCache(): void {
  store.clear();
}
