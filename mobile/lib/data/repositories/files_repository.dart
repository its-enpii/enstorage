import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mime/mime.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../api_client.dart';
import '../models/file_item.dart';
import '../models/folder.dart';
import '../models/storage_summary.dart';
import '../paged_result.dart';
import '../storage/token_storage.dart';

class FilesRepository {
  FilesRepository(this._api, this._tokenStorage);
  final ApiClient _api;
  final TokenStorage _tokenStorage;

  Future<List<Folder>> listFolders({String? parentId, bool? starred}) async {
    final res = await _api.dio.get<Map<String, dynamic>>('/folders', queryParameters: {
      if (parentId != null) 'parent_id': parentId,
      if (starred != null) 'starred': starred,
    });
    return _parseList(res.data, Folder.fromJson);
  }

  /// Paginated file list. Mirrors the web store: pass `page`/`perPage`
  /// and any combination of `search`, `sort`, `dir`, `status`,
  /// `starred`, `mimeType` to drill into results. Returns a
  /// [PagedResult] with the items and the server's pagination metadata.
  Future<PagedResult<FileItem>> pagedFiles({
    String? folderId,
    String? search,
    String? sort,
    String? dir,
    String? status,
    bool? starred,
    String? mimeType,
    int page = 1,
    int perPage = 25,
  }) async {
    final res = await _api.dio.get<Map<String, dynamic>>('/files', queryParameters: {
      if (folderId != null) 'folder_id': folderId,
      if (search != null && search.isNotEmpty) 'search': search,
      if (sort != null) 'sort': sort,
      if (dir != null) 'dir': dir,
      if (status != null) 'status': status,
      if (starred != null) 'starred': starred,
      if (mimeType != null && mimeType.isNotEmpty) 'mime_type': mimeType,
      'page': page,
      'per_page': perPage,
    });
    return _parsePaged(res.data, FileItem.fromJson);
  }

  /// Paginated folder list. Same shape as [pagedFiles]. Server-side
  /// search is supported on the folder name (see FolderController).
  Future<PagedResult<Folder>> pagedFolders({
    String? parentId,
    String? search,
    String? sort,
    String? dir,
    bool? starred,
    int page = 1,
    int perPage = 25,
  }) async {
    final res = await _api.dio.get<Map<String, dynamic>>('/folders', queryParameters: {
      if (parentId != null) 'parent_id': parentId,
      if (search != null && search.isNotEmpty) 'search': search,
      if (sort != null) 'sort': sort,
      if (dir != null) 'dir': dir,
      if (starred != null) 'starred': starred,
      'page': page,
      'per_page': perPage,
    });
    return _parsePaged(res.data, Folder.fromJson);
  }

  Future<List<FileItem>> listFiles({String? folderId, bool? starred}) async {
    final res = await _api.dio.get<Map<String, dynamic>>('/files', queryParameters: {
      if (folderId != null) 'folder_id': folderId,
      if (starred != null) 'starred': starred,
    });
    return _parseList(res.data, FileItem.fromJson);
  }

  Future<Folder> createFolder({required String name, String? parentId}) async {
    final res = await _api.dio.post<Map<String, dynamic>>(
      '/folders',
      data: {
        'name': name,
        if (parentId != null) 'parent_id': parentId,
      },
    );
    return Folder.fromJson(_unwrap(res.data!));
  }

  Future<FileItem> uploadFile({
    required String path,
    required String filename,
    String? folderId,
    void Function(int sent, int total)? onProgress,
  }) async {
    final form = FormData.fromMap({
      'file': await MultipartFile.fromFile(path, filename: filename),
      if (folderId != null) 'folder_id': folderId,
    });
    final res = await _api.dio.post<Map<String, dynamic>>(
      '/files/upload',
      data: form,
      onSendProgress: onProgress,
    );
    return FileItem.fromJson(_unwrap(res.data!));
  }

  Future<FileItem> renameFile(String id, String newName) async {
    final res = await _api.dio.patch<Map<String, dynamic>>(
      '/files/$id',
      data: {'name': newName},
    );
    return FileItem.fromJson(_unwrap(res.data!));
  }

  Future<FileItem> toggleStarFile(String id, bool starred) async {
    final res = await _api.dio.patch<Map<String, dynamic>>(
      '/files/$id',
      data: {'is_starred': starred},
    );
    return FileItem.fromJson(_unwrap(res.data!));
  }

  Future<void> deleteFile(String id) async {
    await _api.dio.delete<void>('/files/$id');
  }

  Future<void> bulkDeleteFiles(List<String> ids) async {
    await _api.dio.post<void>('/files/bulk-delete', data: {'ids': ids});
  }

  Future<FileItem> getFile(String id) async {
    final res = await _api.dio.get<Map<String, dynamic>>('/files/$id');
    return FileItem.fromJson(_unwrap(res.data!));
  }

  Future<void> moveFile(String id, {String? folderId}) async {
    await _api.dio.put<void>('/files/$id/move', data: {
      if (folderId != null) 'folder_id': folderId,
    });
  }

  Future<List<FileItem>> listStarredFiles() async {
    final res = await _api.dio.get<Map<String, dynamic>>(
      '/files',
      queryParameters: {'starred': 'true'},
    );
    return _parseList(res.data, FileItem.fromJson);
  }

  Future<List<Folder>> listStarredFolders() async {
    final res = await _api.dio.get<Map<String, dynamic>>(
      '/folders',
      queryParameters: {'starred': 'true'},
    );
    return _parseList(res.data, Folder.fromJson);
  }

  Future<Map<String, dynamic>> createShareLink(String fileId) async {
    final res = await _api.dio.post<Map<String, dynamic>>(
      '/files/$fileId/share',
    );
    return _unwrap(res.data!);
  }

  Future<void> deleteShareLink(String fileId) async {
    await _api.dio.delete<void>('/files/$fileId/share');
  }

  // ─── Folders ─────────────────────────────────────────────────────

  Future<Folder> getFolder(String id) async {
    final res = await _api.dio.get<Map<String, dynamic>>('/folders/$id');
    return Folder.fromJson(_unwrap(res.data!));
  }

  Future<Folder> updateFolder(String id, {String? name, bool? isStarred}) async {
    final res = await _api.dio.patch<Map<String, dynamic>>(
      '/folders/$id',
      data: {
        if (name != null) 'name': name,
        if (isStarred != null) 'is_starred': isStarred,
      },
    );
    return Folder.fromJson(_unwrap(res.data!));
  }

  Future<Folder> toggleStarFolder(String id, bool starred) async {
    return updateFolder(id, isStarred: starred);
  }

  Future<void> deleteFolder(String id) async {
    await _api.dio.delete<void>('/folders/$id');
  }

  Future<void> moveFolder(String id, {String? parentId}) async {
    await _api.dio.put<void>('/folders/$id/move', data: {
      if (parentId != null) 'parent_id': parentId,
    });
  }

  Future<StorageSummary> storageSummary() async {
    final res = await _api.dio.get<Map<String, dynamic>>('/storage/summary');
    final inner = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
    return StorageSummary.fromJson(inner);
  }

  /// Backend wraps everything in `{ success, data, message, meta }`.
  /// For list endpoints, `data` is the array. For single-resource endpoints,
  /// `data` is the object. `_unwrap` returns the inner object; `_parseList`
  /// returns the inner list.
  static Map<String, dynamic> _unwrap(Map<String, dynamic> body) {
    final inner = body['data'];
    if (inner is Map<String, dynamic>) return inner;
    return body;
  }

  static List<T> _parseList<T>(
    Map<String, dynamic>? body,
    T Function(Map<String, dynamic>) fromJson,
  ) {
    if (body == null) return const [];
    final inner = body['data'];
    if (inner is List) {
      return inner
          .whereType<Map<String, dynamic>>()
          .map(fromJson)
          .toList(growable: false);
    }
    return const [];
  }

  /// Parses the paginated envelope the backend returns for list
  /// endpoints. Tolerant of any of the metadata fields being missing
  /// — falls back to single-page semantics.
  static PagedResult<T> _parsePaged<T>(
    Map<String, dynamic>? body,
    T Function(Map<String, dynamic>) fromJson,
  ) {
    final items = _parseList(body, fromJson);
    if (body == null) {
      return const PagedResult(items: [], page: 1, lastPage: 1, perPage: 25, total: 0);
    }
    final meta = body['meta'];
    Map<String, dynamic>? pagination;
    if (meta is Map) {
      pagination = (meta['pagination'] as Map?)?.cast<String, dynamic>();
    }
    final page = (pagination?['page'] as int?) ?? 1;
    final lastPage = (pagination?['last_page'] as int?) ?? 1;
    final perPage = (pagination?['per_page'] as int?) ?? items.length;
    final total = (pagination?['total'] as int?) ?? items.length;
    return PagedResult<T>(
      items: items,
      page: page,
      lastPage: lastPage,
      perPage: perPage,
      total: total,
    );
  }

  String thumbnailUrl(String fileId, {String? token}) {
    final t = token ?? '';
    return '${_api.dio.options.baseUrl}/files/$fileId/thumbnail${t.isNotEmpty ? '?token=$t' : ''}';
  }

  String downloadUrl(String fileId, {String? token, bool inline = true}) {
    final t = token ?? '';
    final params = <String, String>{'inline': inline ? '1' : '0'};
    if (t.isNotEmpty) params['token'] = t;
    final qs = params.entries.map((e) => '${e.key}=${e.value}').join('&');
    return '${_api.dio.options.baseUrl}/files/$fileId/download?$qs';
  }

  String mimeForFilename(String filename) {
    return lookupMimeType(filename) ?? 'application/octet-stream';
  }

  /// Streams the file bytes to the system clipboard via the OS share
  /// sheet. The download is staged to a temp directory first so it
  /// can be referenced as a real file path. From the share sheet, the
  /// user can pick "Copy to clipboard" (Android) or any clipboard /
  /// save target exposed by the platform.
  Future<void> copyFileToClipboard(String fileId, {String? filename}) async {
    final token = await _tokenStorage.readToken();
    final url = downloadUrl(fileId, token: token, inline: true);
    final dir = await getTemporaryDirectory();
    final name = filename ?? '$fileId.bin';
    final outPath = '${dir.path}/$name';
    await _api.dio.download(url, outPath);
    await Share.shareXFiles([XFile(outPath, name: name)]);
  }
}

final filesRepositoryProvider = Provider<FilesRepository>((ref) {
  return FilesRepository(
    ref.watch(apiClientProvider),
    ref.watch(tokenStorageProvider),
  );
});
