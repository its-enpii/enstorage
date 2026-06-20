enum UploadStatus { pending, uploading, done, failed }

UploadStatus parseUploadStatus(String s) {
  switch (s) {
    case 'uploading':
      return UploadStatus.uploading;
    case 'done':
      return UploadStatus.done;
    case 'failed':
      return UploadStatus.failed;
    default:
      return UploadStatus.pending;
  }
}

/// Mirrors backend `File` resource. See `web/src/lib/api.ts FileItem`.
class FileItem {
  const FileItem({
    required this.id,
    required this.name,
    required this.mimeType,
    required this.size,
    required this.uploadStatus,
    required this.hasThumbnail,
    required this.isStarred,
    this.folderId,
    this.shareToken,
  });

  final String id;
  final String name;
  final String mimeType;
  final int size;
  final UploadStatus uploadStatus;
  final bool hasThumbnail;
  final bool isStarred;
  final String? folderId;
  final String? shareToken;

  factory FileItem.fromJson(Map<String, dynamic> json) => FileItem(
        id: (json['id'] ?? '').toString(),
        name: (json['name'] ?? '') as String,
        mimeType: (json['mime_type'] ?? 'application/octet-stream') as String,
        size: (json['size'] ?? 0) as int,
        uploadStatus: parseUploadStatus((json['upload_status'] ?? 'pending') as String),
        hasThumbnail: (json['has_thumbnail'] ?? false) as bool,
        isStarred: (json['is_starred'] ?? false) as bool,
        folderId: json['folder_id']?.toString(),
        shareToken: json['share_token'] as String?,
      );

  FileItem copyWith({String? name, bool? isStarred, String? shareToken, bool clearShareToken = false}) {
    return FileItem(
      id: id,
      name: name ?? this.name,
      mimeType: mimeType,
      size: size,
      uploadStatus: uploadStatus,
      hasThumbnail: hasThumbnail,
      isStarred: isStarred ?? this.isStarred,
      folderId: folderId,
      shareToken: clearShareToken ? null : (shareToken ?? this.shareToken),
    );
  }
}
