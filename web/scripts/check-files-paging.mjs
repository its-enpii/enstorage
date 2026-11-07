/**
 * Regression guard for files view paging (tab "all" must keep paging while
 * EITHER folders or files still have pages left).
 *
 * Imports the exact helper FilesClient.tsx uses (src/lib/filesPaging.mjs),
 * so a mutation in the real code path fails this script.
 *
 * Usage: node scripts/check-files-paging.mjs  (exit 0 = pass)
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const { resolvePaging } = await import('../src/lib/filesPaging.mjs');

let checks = 0;
function check(name, fn) {
  fn();
  checks++;
  console.log(`ok ${checks} - ${name}`);
}

// a. Owner scenario: file-heavy folder, no subfolders (684 files, 0 folders).
check('all + no more folders + more files => still paging, loads files', () => {
  const r = resolvePaging('all', false, true);
  assert.equal(r.hasMore, true);
  assert.equal(r.loadFiles, true);
  assert.equal(r.loadFolders, false);
});

// b. Both exhausted => sentinel disabled.
check('all + both exhausted => hasMore false', () => {
  const r = resolvePaging('all', false, false);
  assert.equal(r.hasMore, false);
  assert.equal(r.loadFolders, false);
  assert.equal(r.loadFiles, false);
});

// c. Folders tab ignores file paging entirely.
check('folders tab => only loadFolders', () => {
  const r = resolvePaging('folders', true, true);
  assert.equal(r.hasMore, true);
  assert.equal(r.loadFolders, true);
  assert.equal(r.loadFiles, false);
});

// d. Files tab must not be driven by folder paging.
check('files tab => only loadFiles, folder paging does not enable it', () => {
  const r = resolvePaging('files', true, false);
  assert.equal(r.hasMore, false);
  assert.equal(r.loadFolders, false);
  assert.equal(r.loadFiles, false);
});

// e. Both lists still paging => both loaders fire.
check('all + both have more => both loaders active', () => {
  const r = resolvePaging('all', true, true);
  assert.equal(r.hasMore, true);
  assert.equal(r.loadFolders, true);
  assert.equal(r.loadFiles, true);
});

// f. Static invariant: paging refs must NOT be mirrored from state on render.
// Writing `filePageRef.current = filePage` on every render overwrites the
// eager ref advance done inside loadFilesPage, so loadMore keeps requesting
// the same page forever (observed: page 7 requested 7x in production).
check('filesStore does not overwrite paging refs from state on render', () => {
  const src = readFileSync(
    fileURLToPath(new URL('../src/lib/filesStore.tsx', import.meta.url)),
    'utf8',
  );
  const forbidden = [
    /\bfilePageRef\.current\s*=\s*filePage\s*;/g,
    /\bfolderPageRef\.current\s*=\s*folderPage\s*;/g,
  ];
  for (const re of forbidden) {
    const hits = src.match(re) ?? [];
    assert.equal(hits.length, 0, `ref-overwrite regression: found ${re}`);
  }
});

console.log(`\n${checks} checks passed`);
