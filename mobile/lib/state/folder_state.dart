import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/api_client.dart';
import '../data/models/folder.dart';
import '../data/repositories/files_repository.dart';

/// Resolves a folder id to its [Folder] (we only read the name, but
/// returning the full model keeps this open to other fields later).
/// Cached per id, autoDispose so leaving the screen drops the cache.
final folderProvider =
    FutureProvider.autoDispose.family<Folder, String>((ref, id) async {
  // Watch the repo provider so it (and its auth client) is available.
  ref.watch(filesRepositoryProvider);
  final api = ref.read(apiClientProvider);
  final res = await api.dio.get<Map<String, dynamic>>('/folders/$id');
  final inner = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
  // GET /folders/{id} returns the full detail envelope: {folder, breadcrumb,
  // subfolders, files, ...}. The folder we want lives under `folder`.
  final folderJson = (inner['folder'] as Map<String, dynamic>?) ?? inner;
  return Folder.fromJson(folderJson);
});
