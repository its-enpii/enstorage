import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/models/file_item.dart';

/// Singleton ProviderContainer yang di-share antara background FCM handler
/// dan widget tree. Di-init di main() dengan `setAppContainer`.
ProviderContainer? _appProviderContainer;

/// Set singleton container — dipanggil sekali dari main() setelah runApp.
void setAppContainer(ProviderContainer container) {
  _appProviderContainer = container;
}

/// Append signal: FCM upload.complete kirim [FileItem] ke folder tertentu.
/// FilesController di folder tsb listen & prepend ke list (no API call).
final appendFileProvider = StateProvider
    .family<FileItem?, String?>((ref, folderId) => null);

/// Replace signal: caller panggil setelah rename/share/star untuk update
/// row di list. FilesController replace file dengan id yg sama.
final replaceFileProvider = StateProvider
    .family<FileItem?, String?>((ref, folderId) => null);

/// Remove signal: caller panggil setelah delete. FilesController drop
/// file dengan id tsb dari list. `null` folderId = root.
final removeFileProvider = StateProvider
    .family<String?, String?>((ref, folderId) => null);

/// Triggers (dipanggil dari FCM handler, viewer screen, dll).
void notifyAppendFile(String? folderId, FileItem file) {
  final c = _appProviderContainer;
  if (c == null) return;
  c.read(appendFileProvider(folderId).notifier).state = file;
}

void notifyReplaceFile(String? folderId, FileItem file) {
  final c = _appProviderContainer;
  if (c == null) return;
  c.read(replaceFileProvider(folderId).notifier).state = file;
}

void notifyRemoveFile(String? folderId, String id) {
  final c = _appProviderContainer;
  if (c == null) return;
  c.read(removeFileProvider(folderId).notifier).state = id;
}
