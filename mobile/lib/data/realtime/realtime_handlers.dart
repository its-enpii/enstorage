/// Maps RealtimeEvent to Riverpod actions.
///
/// Mirrors the web `handlers.ts` shape but adapts the targets:
/// - file events → notifyAppendFile / notifyReplaceFile / notifyRemoveFile
///   from `lib/state/refresh_signal_state.dart` (the existing pub/sub
///   bus also used by FCM).
/// - folder events → foldersProvider refresh via the accessor registered
///   by `realtime_provider.dart`.
library;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/file_item.dart';
import '../models/folder.dart';
import '../../state/refresh_signal_state.dart' as signals;
import 'realtime_event.dart';

/// Set by the app during boot to expose the parent-keyed files
/// controller family (`filesControllerProvider`). Used to refresh
/// the listing when a folder event arrives.
typedef FilesControllerAccessor = ProviderBase Function(String? parentId);

FilesControllerAccessor? _foldersAccessor;

void registerFoldersProviderAccessor(FilesControllerAccessor fn) {
  _foldersAccessor = fn;
}

bool applyEventToRiverpod(RealtimeEvent event, Ref ref) {
  switch (event.type) {
    case 'file.uploaded':
      final file = _tryParseFile(event.raw);
      if (file == null) return false;
      signals.notifyAppendFile(file.folderId, file);
      return true;

    case 'file.upload.failed':
      final id = event.fileId;
      final folderId = event.folderId;
      if (id == null || id.isEmpty) return false;
      final file = FileItem(
        id: id,
        name: event.raw['name']?.toString() ?? '',
        mimeType: '',
        size: 0,
        uploadStatus: parseUploadStatus('failed'),
        hasThumbnail: false,
        isStarred: false,
        folderId: folderId,
      );
      signals.notifyReplaceFile(folderId, file);
      return true;

    case 'file.moved':
      final file = _tryParseFile(event.raw);
      final prev = event.previousFolderId;
      if (file == null) return false;
      if (prev != null && prev != file.folderId) {
        signals.notifyRemoveFile(prev, file.id);
      }
      if (file.folderId != null) {
        if (prev != null && prev != file.folderId) {
          signals.notifyAppendFile(file.folderId, file);
        } else {
          signals.notifyReplaceFile(file.folderId, file);
        }
      }
      return true;

    case 'file.deleted':
      final id = event.fileId;
      if (id == null || id.isEmpty) return false;
      signals.notifyRemoveFile(event.folderId, id);
      return true;

    case 'file.updated':
      final file = _tryParseFile(event.raw);
      if (file == null) return false;
      signals.notifyReplaceFile(file.folderId, file);
      return true;

    case 'folder.created':
      final folder = _tryParseFolder(event.raw);
      _refreshParentListing(ref, folder?.parentId);
      return folder != null;

    case 'folder.deleted':
      _refreshParentListing(ref, event.parentId);
      return true;

    case 'folder.renamed':
      final folder = _tryParseFolder(event.raw);
      _refreshParentListing(ref, folder?.parentId);
      return folder != null;

    case 'folder.moved':
      final folder = _tryParseFolder(event.raw);
      final prev = event.previousParentId;
      if (prev != null && folder != null && prev != folder.parentId) {
        _refreshParentListing(ref, prev);
      }
      _refreshParentListing(ref, folder?.parentId);
      return folder != null;

    default:
      return false;
  }
}

void _refreshParentListing(Ref ref, String? parentId) {
  final accessor = _foldersAccessor;
  if (accessor == null) return;
  try {
    final provider = accessor(parentId);
    // ignore: invalid_use_of_protected_member
    ref.read(provider).notifier.refresh();
  } catch (_) {
    // Provider may be autoDispose and unmounted — silently skip.
  }
}

FileItem? _tryParseFile(Map<String, dynamic> payload) {
  if (payload['id'] == null) return null;
  // Cast to Map<String, dynamic> for fromJson; payload conforms to
  // backend FileResource shape (snake_case fields).
  final json = Map<String, dynamic>.from(payload);
  try {
    return FileItem.fromJson(json);
  } catch (_) {
    return null;
  }
}

Folder? _tryParseFolder(Map<String, dynamic> payload) {
  if (payload['id'] == null) return null;
  final json = Map<String, dynamic>.from(payload);
  try {
    return Folder.fromJson(json);
  } catch (_) {
    return null;
  }
}