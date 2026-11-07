/**
 * Paging decision for the files view tabs.
 *
 * Tab `all` renders folders + files together, so its sentinel must stay
 * enabled while EITHER list still has pages left, and "load more" must
 * advance whichever list(s) are not exhausted.
 *
 * @typedef {'all' | 'files' | 'folders'} FilesTab
 * @typedef {{ hasMore: boolean, loadFolders: boolean, loadFiles: boolean }} PagingDecision
 */

/**
 * @param {FilesTab} tab
 * @param {boolean} hasMoreFolders
 * @param {boolean} hasMoreFiles
 * @returns {PagingDecision}
 */
export function resolvePaging(tab, hasMoreFolders, hasMoreFiles) {
  if (tab === 'folders') {
    return { hasMore: hasMoreFolders, loadFolders: hasMoreFolders, loadFiles: false };
  }
  if (tab === 'files') {
    return { hasMore: hasMoreFiles, loadFolders: false, loadFiles: hasMoreFiles };
  }
  return {
    hasMore: hasMoreFolders || hasMoreFiles,
    loadFolders: hasMoreFolders,
    loadFiles: hasMoreFiles,
  };
}
