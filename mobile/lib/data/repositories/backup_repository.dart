import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client.dart';
import '../models/backup_queue_item.dart';
import 'files_repository.dart';

/// Repository untuk interaksi server-side yang spesifik Auto Backup:
/// folder mirror, dedup lookup, upload dengan hash.
///
/// Folder mirror strategy:
/// - Path device: `DCIM/Camera/IMG_001.jpg`
/// - Path EnStorage (target): `/DCIM/Camera/IMG_001.jpg`
/// - Auto Backup create folder parent chain `DCIM/Camera` kalau belum ada
///   di server. Cache folder_id di memory + opsional di SharedPreferences
///   supaya next run gak hit listFolders (NB: tidak di-persist di sini —
///   server adalah single source of truth, cache cleared tiap restart).
///
/// Hash dedup:
/// - Client hitung SHA-256 konten lokal (lihat `backup_service.dart`).
/// - `checkExisting` panggil `GET /files/by-hashes?hashes=...`.
/// - Kalau server punya hash → skip upload.
///
/// Upload payload:
/// - `content_hash` field ditambahkan ke multipart `POST /files/upload`.
/// - `client_key` di-set sebagai SHA-256 dari `{absolutePath}|{mtimeMs}|{size}`
///   — stable identifier across re-runs kalau file tidak berubah. Kalau
///   server reject dengan 409 `duplicate_client_key` (race atau file user
///   sudah ada), anggap sukses (idempotent).
class BackupRepository {
  BackupRepository(this._api, this._filesRepo);

  final ApiClient _api;
  final FilesRepository _filesRepo;

  /// Cache in-memory: `parentId|childName` -> folderId. Cleared tiap restart
  /// supaya tidak stale kalau user delete/move folder di web.
  final Map<String, String> _folderCache = {};

  /// Ensure folder chain `segments` exists di bawah `parentId` (null = root).
  /// Return folderId dari leaf segment. Kalau segments kosong, return parentId.
  ///
  /// Mis. `ensureFolderPath(null, ['DCIM', 'Camera'])` return id dari
  /// folder `Camera` yang berada di bawah `DCIM` di root.
  Future<String?> ensureFolderPath(String? parentId, List<String> segments) async {
    if (segments.isEmpty) return parentId;
    var currentParent = parentId;
    for (final name in segments) {
      final cacheKey = '${currentParent ?? 'root'}|$name';
      final cached = _folderCache[cacheKey];
      if (cached != null) {
        currentParent = cached;
        continue;
      }

      // Try find-existing via listFolders(parent) — cheap, sudah cached di
      // server dan paged kecil. Folder scan di sini cukup untuk top-level
      // album (DCIM, Pictures, Download). Untuk nested, recursive call.
      final existing = await _filesRepo.listFolders(parentId: currentParent);
      final match = existing
          .where((f) => f.name == name)
          .cast<dynamic>()
          .map((f) => f.id as String)
          .firstOrNull;

      final String folderId;
      if (match != null) {
        folderId = match;
      } else {
        final created = await _filesRepo.createFolder(
          name: name,
          parentId: currentParent,
        );
        folderId = created.id;
      }
      _folderCache[cacheKey] = folderId;
      currentParent = folderId;
    }
    return currentParent;
  }

  /// Clear in-memory folder cache. Panggil saat ada sinyal realtime
  /// folder_created / folder_deleted supaya cache konsisten dengan state
  /// server (currently no-op — cache cleared on app restart already).
  void invalidateFolderCache() {
    _folderCache.clear();
  }

  /// Look up file existing by device metadata. Return map
  /// `composite-key -> ExistingFileRef` di mana composite key =
  /// `"$path|$mtimeMs|$size"`. Path NOT in map → file belum ada di
  /// server, caller upload. Match → skip (sudah dibackup).
  ///
  /// Composite key pakai separator `|` (path tidak boleh ada `|` —
  /// device paths umumnya filesystem-safe). Chunk ke batch 1000
  /// (server limit) untuk menghindari payload besar.
  Future<Map<String, ExistingFileRef>> checkExistingByMetadata(
    List<({String path, int mtimeMs, int size})> items,
  ) async {
    if (items.isEmpty) return const {};
    final result = <String, ExistingFileRef>{};
    const batchSize = 1000;
    for (var i = 0; i < items.length; i += batchSize) {
      final end = i + batchSize > items.length ? items.length : i + batchSize;
      final chunk = items.sublist(i, end);
      final payload = chunk
          .map((it) => {
                'original_path': it.path,
                'original_mtime_ms': it.mtimeMs,
                'original_size': it.size,
              })
          .toList();
      final res = await _api.dio.post<Map<String, dynamic>>(
        '/files/by-metadata',
        data: {'items': payload},
      );
      final matched = res.data?['data']?['matched'];
      if (matched is! List) continue;
      for (final entry in matched) {
        if (entry is! Map) continue;
        final m = entry.cast<String, dynamic>();
        final path = m['original_path'] as String?;
        final mtime = m['original_mtime_ms'];
        final size = m['original_size'];
        final fileId = m['file_id'] as String?;
        if (path == null || mtime == null || size == null || fileId == null) {
          continue;
        }
        final key = '$path|$mtime|$size';
        result[key] = ExistingFileRef(
          fileId: fileId,
          name: (m['name'] ?? '') as String,
          folderPath: m['folder_path'] as String?,
          folderId: m['folder_id'] as String?,
        );
      }
    }
    return result;
  }

  /// Look up file existing by SHA-256 hash. Return map `hash -> {fileId, name, folderPath}`.
  /// Hash yang tidak ada di server TIDAK muncul di map (caller pakai `.containsKey`
  /// untuk cek apakah skip).
  Future<Map<String, ExistingFileRef>> checkExisting(List<String> hashes) async {
    if (hashes.isEmpty) return const {};

    // Backend limit 100 hash per request — chunk kalau lebih.
    final result = <String, ExistingFileRef>{};
    for (var i = 0; i < hashes.length; i += 100) {
      final chunk = hashes.sublist(i, i + 100 > hashes.length ? hashes.length : i + 100);
      final res = await _api.dio.get<Map<String, dynamic>>(
        '/files/by-hashes',
        queryParameters: {'hashes': chunk.join(',')},
      );
      // Response shape: { data: { data: [...] } } (ApiResponse wrapper).
      final inner = res.data?['data']?['data'];
      if (inner is! List) continue;
      for (final entry in inner) {
        if (entry is! Map) continue;
        final m = entry.cast<String, dynamic>();
        final hash = m['hash'] as String?;
        final fileId = m['file_id'] as String?;
        if (hash == null || fileId == null) continue;
        result[hash] = ExistingFileRef(
          fileId: fileId,
          name: (m['name'] ?? '') as String,
          folderPath: m['folder_path'] as String?,
          folderId: m['folder_id'] as String?,
        );
      }
    }
    return result;
  }

  /// Upload file dengan `content_hash` + idempotent `client_key`.
  ///
  /// Return [BackupQueueItem] updated dengan status `done` + remoteFileId.
  /// Throws kalau HTTP gagal (caller decide retry policy).
  Future<BackupQueueItem> uploadWithHash(
    BackupQueueItem item, {
    required String folderId,
    required String clientKey,
    void Function(int sent, int total)? onProgress,
  }) async {
    final form = FormData.fromMap({
      'file': await MultipartFile.fromFile(
        item.absolutePath,
        filename: item.displayName,
      ),
      'folder_id': folderId,
      'content_hash': item.contentHash,
      'client_key': clientKey,
      // Device metadata — server pakai ini untuk dedup lookup batch
      // via composite index. Path = absolute path on device (Android:
      // /storage/emulated/0/DCIM/...). mtime_ms = file mtime epoch
      // ms. size = byte size. Ketiganya dipakai sebagai tuple key:
      // kalau persis sama → file sudah ada di server (skip).
      'original_path': item.absolutePath,
      'original_mtime_ms': item.modifiedAtMs,
      'original_size': item.sizeBytes,
    });
    final res = await _api.dio.post<Map<String, dynamic>>(
      '/files/upload',
      data: form,
      onSendProgress: (sent, total) {
        onProgress?.call(sent, total);
      },
    );
    final inner = res.data?['data'];
    final accepted = (inner is Map ? inner['accepted'] : null) as List?;
    final remoteId = (accepted is List && accepted.isNotEmpty)
        ? accepted.first['file_id']?.toString()
        : null;
    return item.copyWith(
      status: BackupItemStatus.done,
      progressBytes: item.sizeBytes,
      remoteFileId: remoteId,
    );
  }

  /// Poll upload status dari server. Endpoint `GET /files/{id}/status`
  /// return `data.status` ∈ {pending, uploading, done, failed}.
  /// Return null kalau remote ID kosong / response tidak valid.
  Future<String?> pollFileStatus(String remoteFileId) async {
    if (remoteFileId.isEmpty) return null;
    try {
      final res = await _api.dio.get<Map<String, dynamic>>(
        '/files/$remoteFileId/status',
      );
      return (res.data?['data'] as Map?)?['status'] as String?;
    } catch (_) {
      return null;
    }
  }
}

class ExistingFileRef {
  const ExistingFileRef({
    required this.fileId,
    required this.name,
    this.folderPath,
    this.folderId,
  });
  final String fileId;
  final String name;
  final String? folderPath;
  final String? folderId;
}

extension _FirstOrNullExt<T> on Iterable<T> {
  T? get firstOrNull {
    final it = iterator;
    if (it.moveNext()) return it.current;
    return null;
  }
}

final backupRepositoryProvider = Provider<BackupRepository>((ref) {
  return BackupRepository(
    ref.watch(apiClientProvider),
    ref.watch(filesRepositoryProvider),
  );
});