/// Canonical event payload shapes for Reverb WS broadcasts.
///
/// Backend (`app/Support/WebhookPayload.php` + event classes) emits
/// these on channels:
///   - file.* → `client.{client_key}.folder.{folder_id|root}`
///   - folder.* → `folder.{user_id}.{folder_id|root}`
///
/// Handlers (`handlers.dart`) consume these into Riverpod providers.
library;

import 'dart:convert' show jsonDecode;

class RealtimeEvent {
  RealtimeEvent._({
    required this.type,
    required this.raw,
  });

  final String type;
  final Map<String, dynamic> raw;

  // ── File events ───────────────────────────────────────────────
  factory RealtimeEvent.fileUploaded(Map<String, dynamic> data) =>
      RealtimeEvent._(type: 'file.uploaded', raw: data);

  factory RealtimeEvent.fileUploadFailed(Map<String, dynamic> data) =>
      RealtimeEvent._(type: 'file.upload.failed', raw: data);

  factory RealtimeEvent.fileMoved(Map<String, dynamic> data) =>
      RealtimeEvent._(type: 'file.moved', raw: data);

  factory RealtimeEvent.fileDeleted(Map<String, dynamic> data) =>
      RealtimeEvent._(type: 'file.deleted', raw: data);

  factory RealtimeEvent.fileUpdated(Map<String, dynamic> data) =>
      RealtimeEvent._(type: 'file.updated', raw: data);

  // ── Folder events ─────────────────────────────────────────────
  factory RealtimeEvent.folderCreated(Map<String, dynamic> data) =>
      RealtimeEvent._(type: 'folder.created', raw: data);

  factory RealtimeEvent.folderDeleted(Map<String, dynamic> data) =>
      RealtimeEvent._(type: 'folder.deleted', raw: data);

  factory RealtimeEvent.folderRenamed(Map<String, dynamic> data) =>
      RealtimeEvent._(type: 'folder.renamed', raw: data);

  factory RealtimeEvent.folderMoved(Map<String, dynamic> data) =>
      RealtimeEvent._(type: 'folder.moved', raw: data);

  // Convenience getters used by handlers.
  String? get fileId => raw['file_id']?.toString();
  String? get folderId => raw['folder_id']?.toString();
  String? get parentId => raw['parent_id']?.toString();
  String? get previousFolderId => raw['previous_folder_id']?.toString();
  String? get previousParentId => raw['previous_parent_id']?.toString();
  bool get renamed => raw['renamed'] == true;
}

/// Coerce a raw Pusher event payload (Pusher `event` + `data` strings)
/// into a typed [RealtimeEvent]. Returns null when the event name is
/// unknown or payload is malformed — caller logs + drops.
RealtimeEvent? parseRealtimePayload(String eventName, Object? rawData) {
  if (rawData == null) return null;
  final Map<String, dynamic> data;
  if (rawData is String) {
    try {
      if (rawData.isEmpty) return null;
      data = jsonDecode(rawData) as Map<String, dynamic>;
    } catch (_) {
      return null;
    }
  } else if (rawData is Map) {
    data = Map<String, dynamic>.from(rawData as Map);
  } else {
    return null;
  }

  switch (eventName) {
    case 'App\\Events\\FileUploadedBroadcast':
      if (data['id'] == null) return null;
      return RealtimeEvent.fileUploaded(data);
    case 'App\\Events\\FileUploadFailedBroadcast':
      return RealtimeEvent.fileUploadFailed(data);
    case 'App\\Events\\FileMovedBroadcast':
      if (data['id'] == null) return null;
      return RealtimeEvent.fileMoved(data);
    case 'App\\Events\\FileDeletedBroadcast':
      return RealtimeEvent.fileDeleted(data);
    case 'App\\Events\\FileUpdatedBroadcast':
      if (data['id'] == null) return null;
      return RealtimeEvent.fileUpdated(data);
    case 'App\\Events\\FolderCreatedBroadcast':
      if (data['id'] == null) return null;
      return RealtimeEvent.folderCreated(data);
    case 'App\\Events\\FolderDeletedBroadcast':
      return RealtimeEvent.folderDeleted(data);
    case 'App\\Events\\FolderRenamedBroadcast':
      if (data['id'] == null) return null;
      return RealtimeEvent.folderRenamed(data);
    case 'App\\Events\\FolderMovedBroadcast':
      if (data['id'] == null) return null;
      return RealtimeEvent.folderMoved(data);
    default:
      return null;
  }
}