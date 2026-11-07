import type { Folder } from '@/lib/api';

/** Folder terkunci / di dalam folder terkunci tidak boleh dipakai. */
export function isLockedScope(f: Folder, byId: Map<string, Folder>): boolean {
  if (f.is_locked) return true;
  let cur = f.parent_id;
  const guard = new Set<string>();
  while (cur && !guard.has(cur)) {
    guard.add(cur);
    const parent = byId.get(cur);
    if (!parent) return false;
    if (parent.is_locked) return true;
    cur = parent.parent_id;
  }
  return false;
}

/**
 * Folder terkunci TERATAS yang membayangi `f` (paling dekat root), atau null.
 *
 * Password hanya hidup di folder ber-`is_locked`; subfolder di dalamnya tidak
 * punya hash sendiri (`FolderController@unlock` menolak 409 "Folder tidak
 * terkunci"). Karena itu unlock wajib diarahkan ke ancestor yang benar-benar
 * terkunci, bukan ke folder tujuan yang dipilih.
 */
export function lockedAncestorOf(f: Folder, byId: Map<string, Folder>): string | null {
  // Telusuri rantai f -> root, kembalikan elemen PALING ATAS yang is_locked.
  const chain: Folder[] = [f];
  let cur = f.parent_id;
  const guard = new Set<string>();
  while (cur && !guard.has(cur)) {
    guard.add(cur);
    const parent = byId.get(cur);
    if (!parent) break;
    chain.push(parent);
    cur = parent.parent_id;
  }
  for (let i = chain.length - 1; i >= 0; i -= 1) {
    if (chain[i].is_locked) return chain[i].id;
  }
  return null;
}
