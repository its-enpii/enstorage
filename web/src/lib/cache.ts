// Per-user typed cache backed by localStorage. Keyed by user_id so cache
// doesn't leak across accounts on the same browser. Each entry has a TTL;
// expired entries are treated as misses on read.

const NS = 'enstorage_cache';

type Entry<T> = {
  v: T;
  e: number; // expiry timestamp (ms)
};

function fullKey(userId: string, key: string): string {
  return `${NS}:${userId}:${key}`;
}

export function cacheGet<T>(userId: string, key: string): T | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.localStorage.getItem(fullKey(userId, key));
    if (!raw) return null;
    const entry = JSON.parse(raw) as Entry<T>;
    if (entry.e < Date.now()) {
      window.localStorage.removeItem(fullKey(userId, key));
      return null;
    }
    return entry.v;
  } catch {
    return null;
  }
}

export function cacheSet<T>(userId: string, key: string, value: T, ttlMs = 5 * 60_000): void {
  if (typeof window === 'undefined') return;
  try {
    const entry: Entry<T> = { v: value, e: Date.now() + ttlMs };
    window.localStorage.setItem(fullKey(userId, key), JSON.stringify(entry));
  } catch {
    // quota or serialization error — ignore
  }
}

export function cacheRemove(userId: string, key: string): void {
  if (typeof window === 'undefined') return;
  window.localStorage.removeItem(fullKey(userId, key));
}

export function cacheInvalidatePrefix(userId: string, keyPrefix: string): void {
  if (typeof window === 'undefined') return;
  const full = `${NS}:${userId}:${keyPrefix}`;
  const toRemove: string[] = [];
  for (let i = 0; i < window.localStorage.length; i++) {
    const k = window.localStorage.key(i);
    if (k && k.startsWith(full)) toRemove.push(k);
  }
  toRemove.forEach((k) => window.localStorage.removeItem(k));
}

export function cacheClearAll(userId: string): void {
  if (typeof window === 'undefined') return;
  const prefix = `${NS}:${userId}:`;
  const toRemove: string[] = [];
  for (let i = 0; i < window.localStorage.length; i++) {
    const k = window.localStorage.key(i);
    if (k && k.startsWith(prefix)) toRemove.push(k);
  }
  toRemove.forEach((k) => window.localStorage.removeItem(k));
}
