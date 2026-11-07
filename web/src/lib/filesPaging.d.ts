export type FilesTab = 'all' | 'files' | 'folders';
export type PagingDecision = { hasMore: boolean; loadFolders: boolean; loadFiles: boolean };
export function resolvePaging(
  tab: FilesTab,
  hasMoreFolders: boolean,
  hasMoreFiles: boolean,
): PagingDecision;
