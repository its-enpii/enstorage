'use client';

/**
 * Posisi scroll container AppShell per folder path (dibaca saat kembali).
 *
 * Scroll di app terjadi di div `overflow-y-auto` milik AppShell, BUKAN di
 * `window` — jadi `window.scrollY` selalu 0. Next.js App Router juga tidak
 * memulihkan scroll container itu saat tombol back. Entri disimpan di
 * sessionStorage supaya bertahan selama tab hidup dan per-user + per-path.
 */

export type SavedScroll = {
  top: number;
  /** Render view saat disimpan; restore hanya bila cocok dengan (folderId, search, type). */
  folderId: string | null;
  search: string;
  typeFilter: string;
  /** Halaman yang sudah dimuat saat disimpan (untuk clamp bila cache lebih pendek). */
  folderPage: number;
  filePage: number;
  /** Batas scroll saat disimpan — dipakai clamp bila konten saat restore lebih pendek. */
  scrollHeight: number;
  clientHeight: number;
};

const PREFIX = 'files:scroll:';

export function filesScrollKey(userId: string, path: string): string {
  const normalized = path === '' || path === '/' ? '/' : `/${path.replace(/^\/+|\/+$/g, '')}`;
  return `${PREFIX}${userId}:${normalized}`;
}

export function saveFilesScroll(userId: string, path: string, value: SavedScroll): void {
  if (typeof window === 'undefined' || !userId) return;
  try {
    window.sessionStorage.setItem(filesScrollKey(userId, path), JSON.stringify(value));
  } catch {
    // quota / private mode — restore is a nice-to-have
  }
}

export function readFilesScroll(userId: string, path: string): SavedScroll | null {
  if (typeof window === 'undefined' || !userId) return null;
  try {
    const raw = window.sessionStorage.getItem(filesScrollKey(userId, path));
    if (!raw) return null;
    const parsed = JSON.parse(raw) as Partial<SavedScroll>;
    if (typeof parsed.top !== 'number' || !Number.isFinite(parsed.top)) return null;
    return {
      top: parsed.top,
      folderId: parsed.folderId ?? null,
      search: parsed.search ?? '',
      typeFilter: parsed.typeFilter ?? '',
      folderPage: parsed.folderPage ?? 0,
      filePage: parsed.filePage ?? 0,
      scrollHeight: parsed.scrollHeight ?? 0,
      clientHeight: parsed.clientHeight ?? 0,
    };
  } catch {
    return null;
  }
}

export function clearFilesScroll(userId: string, path: string): void {
  if (typeof window === 'undefined' || !userId) return;
  try {
    window.sessionStorage.removeItem(filesScrollKey(userId, path));
  } catch {
    // ignore
  }
}

/**
 * Container scroll aktif: div `overflow-y-auto` terdekat dari `node`
 * (AppShell baris ~90). Fallback ke elemen `overflow-y-auto` pertama yang
 * benar-benar bisa di-scroll, lalu documentElement.
 */
export function findScrollContainer(node: HTMLElement | null): HTMLElement {
  let el: HTMLElement | null = node;
  while (el) {
    const overflowY = window.getComputedStyle(el).overflowY;
    if ((overflowY === 'auto' || overflowY === 'scroll') && el.scrollHeight > el.clientHeight) {
      return el;
    }
    el = el.parentElement;
  }
  const candidates = Array.from(document.querySelectorAll<HTMLElement>('.overflow-y-auto')).filter(
    (candidate) => candidate.scrollHeight > candidate.clientHeight,
  );
  return candidates[0] ?? document.documentElement;
}
