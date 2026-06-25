import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/models/file_item.dart';
import '../data/models/folder.dart';
import '../data/paged_result.dart';
import '../data/repositories/files_repository.dart';
import 'refresh_signal_state.dart';

enum FileSort { name, size, createdAt, uploadedAt }

extension FileSortWire on FileSort {
  String get param {
    switch (this) {
      case FileSort.name:
        return 'name';
      case FileSort.size:
        return 'size';
      case FileSort.createdAt:
        return 'created_at';
      case FileSort.uploadedAt:
        return 'uploaded_at';
    }
  }
}

/// High-level file type filter. Maps to the backend's `mime_type`
/// query parameter (which does a `LIKE 'prefix%'` match).
enum FileTypeFilter { all, image, video, audio, pdf, doc, text }

extension FileTypeFilterWire on FileTypeFilter {
  /// Returns the `mime_type` prefix to send to the server, or null
  /// when the user wants every type.
  String? get mimePrefix {
    switch (this) {
      case FileTypeFilter.all:
        return null;
      case FileTypeFilter.image:
        return 'image/';
      case FileTypeFilter.video:
        return 'video/';
      case FileTypeFilter.audio:
        return 'audio/';
      case FileTypeFilter.pdf:
        return 'application/pdf';
      case FileTypeFilter.doc:
        // Word + Sheets + Slides + plain text — the common "doc" bucket.
        return 'application/vnd.openxmlformats-officedocument';
      case FileTypeFilter.text:
        return 'text/';
    }
  }
}

@immutable
class FilesData {
  const FilesData({
    this.folders = const [],
    this.files = const [],
    this.hasMoreFolders = false,
    this.hasMoreFiles = false,
    this.folderTotal = 0,
    this.fileTotal = 0,
  });
  final List<Folder> folders;
  final List<FileItem> files;
  final bool hasMoreFolders;
  final bool hasMoreFiles;
  final int folderTotal;
  final int fileTotal;

  bool get isEmpty => folders.isEmpty && files.isEmpty;

  FilesData copyWith({
    List<Folder>? folders,
    List<FileItem>? files,
    bool? hasMoreFolders,
    bool? hasMoreFiles,
    int? folderTotal,
    int? fileTotal,
  }) {
    return FilesData(
      folders: folders ?? this.folders,
      files: files ?? this.files,
      hasMoreFolders: hasMoreFolders ?? this.hasMoreFolders,
      hasMoreFiles: hasMoreFiles ?? this.hasMoreFiles,
      folderTotal: folderTotal ?? this.folderTotal,
      fileTotal: fileTotal ?? this.fileTotal,
    );
  }
}

/// Filter options that map directly to backend query parameters.
@immutable
class FilesFilter {
  const FilesFilter({
    this.search = '',
    this.sort = FileSort.createdAt,
    this.ascending = false,
    this.starredOnly = false,
    this.status,
    this.type = FileTypeFilter.all,
  });
  final String search;
  final FileSort sort;
  final bool ascending;
  final bool starredOnly;

  /// Server-side file status. null = no filter (backend already
  /// excludes `failed` by default — see FileController::index).
  final String? status;

  /// File type filter — server-side, see [FileTypeFilter].
  final FileTypeFilter type;

  FilesFilter copyWith({
    String? search,
    FileSort? sort,
    bool? ascending,
    bool? starredOnly,
    Object? status = _unset,
    FileTypeFilter? type,
  }) {
    return FilesFilter(
      search: search ?? this.search,
      sort: sort ?? this.sort,
      ascending: ascending ?? this.ascending,
      starredOnly: starredOnly ?? this.starredOnly,
      status: status == _unset ? this.status : status as String?,
      type: type ?? this.type,
    );
  }

  static const _unset = Object();
}

class FilesController
    extends StateNotifier<AsyncValue<FilesData>> {
  FilesController(this._repo, {String? parentId, FilesFilter? filter})
      : _parentId = parentId,
        _filter = filter ?? const FilesFilter(),
        super(const AsyncValue.loading()) {
    _load();
  }

  final FilesRepository _repo;
  String? _parentId;
  FilesFilter _filter;

  int _folderPage = 1;
  int _filePage = 1;
  int _folderLastPage = 1;
  int _fileLastPage = 1;
  bool _loadingMore = false;
  bool _isRefreshing = false;

  /// Debounce timer for filter-driven refetches (search input).
  Timer? _debounce;

  FilesFilter get filter => _filter;
  bool get loadingMore => _loadingMore;

  /// True when a refetch is in-flight but the previous data is still
  /// being shown (so the UI can show a subtle progress bar instead
  /// of blanking the list).
  bool get isRefreshing => _isRefreshing;

  /// Reload from page 1, replacing current results. Call after a
  /// filter or sort change.
  Future<void> refresh() async {
    _folderPage = 1;
    _filePage = 1;
    await _runRefresh(isInitialLoad: state.valueOrNull == null);
  }

  /// Reset the search query and refetch. Used by the "Clear search"
  /// CTA in the empty state so the user can recover without restarting.
  void clearSearch() {
    if (_filter.search.isEmpty) return;
    _filter = _filter.copyWith(search: '');
    refresh();
  }

  /// Debounced variant of [refresh] — cancels any pending refetch and
  /// waits [delay] before firing. Use for live inputs (search field).
  void scheduleRefresh({Duration delay = const Duration(milliseconds: 300)}) {
    _debounce?.cancel();
    _debounce = Timer(delay, refresh);
  }

  void setSearch(String q) {
    _filter = _filter.copyWith(search: q);
    scheduleRefresh();
  }

  void setSort(FileSort sort, {bool? ascending}) {
    _filter = _filter.copyWith(
      sort: sort,
      ascending: ascending ?? true,
    );
    refresh();
  }

  void toggleStarred() {
    _filter = _filter.copyWith(starredOnly: !_filter.starredOnly);
    refresh();
  }

  void setStatus(String? status) {
    _filter = _filter.copyWith(status: status);
    refresh();
  }

  void setType(FileTypeFilter type) {
    _filter = _filter.copyWith(type: type);
    refresh();
  }

  /// Append the next page of both folders and files. The scroll
  /// listener calls this when the user nears the bottom of the list.
  Future<void> loadMore() async {
    if (_loadingMore) return;
    final current = state.valueOrNull;
    if (current == null) return;
    if (!current.hasMoreFolders && !current.hasMoreFiles) return;

    _loadingMore = true;
    try {
      List<Folder> nextFolders = const [];
      List<FileItem> nextFiles = const [];
      bool moreFolders = current.hasMoreFolders;
      bool moreFiles = current.hasMoreFiles;

      if (current.hasMoreFolders && _folderPage < _folderLastPage) {
        final next = _folderPage + 1;
        final res = await _fetchFoldersPage(next);
        nextFolders = res.items;
        _folderPage = res.page;
        _folderLastPage = res.lastPage;
        moreFolders = res.hasMore;
      }
      if (current.hasMoreFiles && _filePage < _fileLastPage) {
        final next = _filePage + 1;
        final res = await _fetchFilesPage(next);
        nextFiles = res.items;
        _filePage = res.page;
        _fileLastPage = res.lastPage;
        moreFiles = res.hasMore;
      }

      state = AsyncValue.data(current.copyWith(
        folders: _mergeUnique(current.folders, nextFolders),
        files: _mergeUnique(current.files, nextFiles),
        hasMoreFolders: moreFolders,
        hasMoreFiles: moreFiles,
      ));
    } catch (e, st) {
      // Keep existing data on pagination failure; surface a snackbar
      // from the caller if desired. Don't blow away the page.
      debugPrint('loadMore failed: $e\n$st');
    } finally {
      _loadingMore = false;
    }
  }

  Future<void> _load() => _runRefresh(isInitialLoad: true);

  /// Internal refetch path. On the very first load there's no prior
  /// data to show, so we still flip [AsyncValue] to loading and the
  /// UI renders a full-page spinner. On any subsequent refetch (search,
  /// sort, filter) we keep the previous [FilesData] in place and just
  /// toggle [isRefreshing] so the UI can show a subtle indicator — no
  /// blank flash.
  Future<void> _runRefresh({required bool isInitialLoad}) async {
    if (isInitialLoad) {
      state = const AsyncValue.loading();
    } else {
      _isRefreshing = true;
    }
    try {
      final foldersRes = await _fetchFoldersPage(1);
      final filesRes = await _fetchFilesPage(1);
      _folderPage = foldersRes.page;
      _folderLastPage = foldersRes.lastPage;
      _filePage = filesRes.page;
      _fileLastPage = filesRes.lastPage;
      state = AsyncValue.data(FilesData(
        folders: foldersRes.items,
        files: filesRes.items,
        hasMoreFolders: foldersRes.hasMore,
        hasMoreFiles: filesRes.hasMore,
        folderTotal: foldersRes.total,
        fileTotal: filesRes.total,
      ));
    } catch (e, st) {
      // On a non-initial failure keep the prior data visible and just
      // expose the error via a separate signal — caller decides how
      // to surface it (snackbar). For initial load, fall back to the
      // error state so the error widget can take over.
      if (isInitialLoad) {
        state = AsyncValue.error(e, st);
      } else {
        // Preserve last good data; UI continues to render it.
        debugPrint('refresh failed: $e\n$st');
      }
    } finally {
      _isRefreshing = false;
    }
  }

  Future<PagedResult<Folder>> _fetchFoldersPage(int page) {
    return _repo.pagedFolders(
      parentId: _parentId,
      search: _filter.search.isEmpty ? null : _filter.search,
      sort: _filter.sort.param,
      dir: _filter.ascending ? 'asc' : 'desc',
      starred: _filter.starredOnly ? true : null,
      page: page,
    );
  }

  Future<PagedResult<FileItem>> _fetchFilesPage(int page) {
    return _repo.pagedFiles(
      folderId: _parentId,
      search: _filter.search.isEmpty ? null : _filter.search,
      sort: _filter.sort.param,
      dir: _filter.ascending ? 'asc' : 'desc',
      status: _filter.status,
      starred: _filter.starredOnly ? true : null,
      mimeType: _filter.type.mimePrefix,
      page: page,
    );
  }

  /// Append [incoming] to [existing] and dedupe by id. New items
  /// appear at the bottom — caller decides where to place them.
  static List<T> _mergeUnique<T>(List<T> existing, List<T> incoming) {
    if (incoming.isEmpty) return existing;
    final seen = {for (final e in existing) _idOf(e)};
    final out = [...existing];
    for (final item in incoming) {
      final id = _idOf(item);
      if (seen.add(id)) out.add(item);
    }
    return out;
  }

  static dynamic _idOf(dynamic item) {
    if (item is Folder) return item.id;
    if (item is FileItem) return item.id;
    return null;
  }

  Future<Folder> createFolder(String name) async {
    final folder = await _repo.createFolder(name: name, parentId: _parentId);
    final current = state.valueOrNull ?? const FilesData();
    state = AsyncValue.data(
      current.copyWith(folders: [folder, ...current.folders]),
    );
    return folder;
  }

  void removeFile(String id) {
    final current = state.valueOrNull;
    if (current == null) return;
    state = AsyncValue.data(current.copyWith(
      files: current.files.where((f) => f.id != id).toList(),
    ));
  }

  /// Bulk-remove files (untuk delete multi). Update list lokal, no API call.
  void removeFiles(Iterable<String> ids) {
    final current = state.valueOrNull;
    if (current == null) return;
    final idSet = ids.toSet();
    final removed = current.files.where((f) => idSet.contains(f.id)).length;
    if (removed == 0) return;
    state = AsyncValue.data(current.copyWith(
      files: current.files.where((f) => !idSet.contains(f.id)).toList(),
      fileTotal: (current.fileTotal - removed).clamp(0, 1 << 30),
    ));
  }

  /// Prepend a freshly-uploaded file to the list (used by FCM
  /// upload.complete handler — no API call, no full refresh).
  /// Idempotent: kalau file dengan id yg sama udah ada, gak duplikat.
  void prependFile(FileItem file) {
    final current = state.valueOrNull;
    if (current == null) return;
    // Cek folder cocok: kalau controller di-scope ke folder tertentu,
    // cuma append kalau file.folderId match. Untuk root (parentId == null),
    // cuma append kalau file.folderId juga null.
    final myFolder = _parentId;
    if (myFolder != (file.folderId ?? '')) return;
    // Skip kalau udah ada
    if (current.files.any((f) => f.id == file.id)) return;
    state = AsyncValue.data(current.copyWith(
      files: [file, ...current.files],
      fileTotal: current.fileTotal + 1,
    ));
  }

  /// Replace file dengan id yg sama di list (untuk update setelah toggle star,
  /// rename, dll). Listeners di scope yang sama akan rebuild otomatis.
  /// Gak throw kalau gak ketemu — biar UI gak crash kalau file di folder lain.
  void replaceFile(FileItem file) {
    final current = state.valueOrNull;
    if (current == null) return;
    final idx = current.files.indexWhere((f) => f.id == file.id);
    if (idx < 0) return;
    final updated = [...current.files];
    updated[idx] = file;
    state = AsyncValue.data(current.copyWith(files: updated));
  }

  /// Replace folder dengan id yg sama di list (untuk update setelah
  /// toggle star, share, dll). Gak throw kalau gak ketemu.
  void replaceFolder(Folder folder) {
    final current = state.valueOrNull;
    if (current == null) return;
    final idx = current.folders.indexWhere((f) => f.id == folder.id);
    if (idx < 0) return;
    final updated = [...current.folders];
    updated[idx] = folder;
    state = AsyncValue.data(current.copyWith(folders: updated));
  }

  void removeFolder(String id) {
    final current = state.valueOrNull;
    if (current == null) return;
    state = AsyncValue.data(current.copyWith(
      folders: current.folders.where((f) => f.id != id).toList(),
    ));
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }
}

/// `parentId == null` is the root; folder drill-down uses a separate
/// AutoDispose family keyed by parentId.
final filesControllerProvider = StateNotifierProvider.autoDispose
    .family<FilesController, AsyncValue<FilesData>, String?>((ref, parentId) {
  final controller = FilesController(
    ref.watch(filesRepositoryProvider),
    parentId: parentId,
  );
  // Listen ke FCM upload.complete: append file baru ke list (no API call).
  ref.listen<FileItem?>(appendFileProvider(parentId), (prev, next) {
    if (next == null) return;
    controller.prependFile(next);
    // ignore: unused_result
    ref.read(appendFileProvider(parentId).notifier).state = null;
  });
  // Listen ke mutation dari viewer / FCM (rename, share, star update).
  ref.listen<FileItem?>(replaceFileProvider(parentId), (prev, next) {
    if (next == null) return;
    controller.replaceFile(next);
    // ignore: unused_result
    ref.read(replaceFileProvider(parentId).notifier).state = null;
  });
  // Listen ke delete events.
  ref.listen<String?>(removeFileProvider(parentId), (prev, next) {
    if (next == null) return;
    controller.removeFile(next);
    // ignore: unused_result
    ref.read(removeFileProvider(parentId).notifier).state = null;
  });
  return controller;
});
