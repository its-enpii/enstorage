/**
 * Regression guard for merge-state invalidation.
 *
 * Static assertions on the real sources (no duplicate logic to drift):
 *  1. `handleMerged()` calls `invalidateAllFoldersCache()` BEFORE
 *     `reloadAllFolders()` — the `all-folders` localStorage entry must be
 *     removed explicitly, not merely overwritten by the async reload.
 *  2. `invalidateAllFoldersCache` in filesStore removes
 *     `cacheRemove(userId, ALL_FOLDERS_CACHE_KEY)` and empties the tree.
 *
 * Usage: node scripts/check-merge-invalidation.mjs  (exit 0 = pass)
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const clientSrc = readFileSync(
  join(here, '..', 'src', 'app', '(app)', 'files', '[[...path]]', 'FilesClient.tsx'),
  'utf8',
);
const storeSrc = readFileSync(join(here, '..', 'src', 'lib', 'filesStore.tsx'), 'utf8');

let checks = 0;
function check(name, fn) {
  fn();
  checks++;
  console.log(`ok ${checks} - ${name}`);
}

/** Potongan isi `handleMerged(...) { ... }` sampai fungsi berikutnya. */
function handleMergedBody(src) {
  const start = src.indexOf('async function handleMerged(');
  assert.notEqual(start, -1, 'handleMerged() tidak ditemukan');
  const end = src.indexOf('function scheduleQueuedMergeRefresh', start);
  return src.slice(start, end === -1 ? undefined : end);
}

function storeInvalidationBody(src) {
  const start = src.indexOf('const invalidateAllFoldersCache = useCallback');
  assert.notEqual(start, -1, 'invalidateAllFoldersCache tidak ditemukan');
  return src.slice(start, src.indexOf('}, [userId]);', start));
}

check('handleMerged memanggil invalidateAllFoldersCache SEBELUM reloadAllFolders', () => {
  const body = handleMergedBody(clientSrc);
  const invalidateAt = body.indexOf('invalidateAllFoldersCache()');
  const reloadAt = body.indexOf('reloadAllFolders()');
  assert.notEqual(invalidateAt, -1, 'handleMerged harus memanggil invalidateAllFoldersCache()');
  assert.notEqual(reloadAt, -1, 'handleMerged harus memanggil reloadAllFolders()');
  assert.ok(
    invalidateAt < reloadAt,
    `invalidateAllFoldersCache() (index ${invalidateAt}) harus dipanggil sebelum reloadAllFolders() (index ${reloadAt})`,
  );
});

check('handleMerged tetap menginvalidasi view aktif + halaman /folders + scroll', () => {
  const body = handleMergedBody(clientSrc);
  for (const call of [
    'invalidateCurrentCache()',
    'invalidateFoldersPageCache()',
    'refreshReplace()',
    'clearSelection()',
    'invalidateMergedScroll(',
  ]) {
    assert.ok(body.includes(call), `handleMerged harus memanggil ${call}`);
  }
});

check('invalidateAllFoldersCache memanggil cacheRemove(... ALL_FOLDERS_CACHE_KEY ...)', () => {
  const body = storeInvalidationBody(storeSrc);
  assert.match(
    body,
    /cacheRemove\(\s*userId\s*,\s*ALL_FOLDERS_CACHE_KEY\s*\)/,
    'invalidateAllFoldersCache harus memanggil cacheRemove(userId, ALL_FOLDERS_CACHE_KEY)',
  );
  assert.match(body, /setAllFoldersRaw\(\[\]\)/, 'invalidateAllFoldersCache harus mengosongkan allFoldersRaw');
});

check('invalidateAllFoldersCache terekspos di context', () => {
  assert.match(storeSrc, /invalidateAllFoldersCache: \(\) => void;/, 'tipe Ctx harus mendeklarasikan invalidateAllFoldersCache');
  assert.match(storeSrc, /^\s{4}invalidateAllFoldersCache,$/m, 'nilai context harus menyertakan invalidateAllFoldersCache');
});

console.log(`\n${checks} checks passed`);
