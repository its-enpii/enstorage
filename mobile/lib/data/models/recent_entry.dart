/// One item in the /recent feed — either a folder or a file, discriminated
/// by [type]. Root-level only (no parent / folder_id).
class RecentEntry {
  const RecentEntry({
    required this.type,
    required this.id,
    required this.name,
    required this.isStarred,
    required this.updatedAt,
    required this.createdAt,
    this.mimeType,
    this.size,
    this.folderId,
    this.hasThumbnail,
    this.uploadStatus,
    this.shareToken,
    this.originalName,
    this.filesCount,
    this.foldersCount,
    this.totalSize,
    this.path,
  });

  final RecentEntryType type;
  final String id;
  final String name;
  final bool isStarred;
  final DateTime updatedAt;
  final DateTime createdAt;

  // File-only fields.
  final String? mimeType;
  final int? size;
  final String? folderId;
  final bool? hasThumbnail;
  final String? uploadStatus;
  final String? shareToken;
  final String? originalName;

  // Folder-only fields.
  final int? filesCount;
  final int? foldersCount;
  final int? totalSize;
  final String? path;

  bool get isFolder => type == RecentEntryType.folder;
  bool get isFile => type == RecentEntryType.file;

  factory RecentEntry.fromJson(Map<String, dynamic> json) {
    final type = (json['type'] as String?) == 'folder'
        ? RecentEntryType.folder
        : RecentEntryType.file;
    final updated = _parseDate(json['updated_at']) ?? _parseDate(json['created_at']);
    final created = _parseDate(json['created_at']) ?? updated ?? DateTime.now();
    return RecentEntry(
      type: type,
      id: (json['id'] ?? '').toString(),
      name: (json['name'] ?? '') as String,
      isStarred: (json['is_starred'] ?? false) as bool,
      updatedAt: updated ?? DateTime.now(),
      createdAt: created,
      mimeType: json['mime_type'] as String?,
      size: (json['size'] as num?)?.toInt(),
      folderId: json['folder_id']?.toString(),
      hasThumbnail: json['has_thumbnail'] as bool?,
      uploadStatus: json['upload_status'] as String?,
      shareToken: json['share_token'] as String?,
      originalName: json['original_name'] as String?,
      filesCount: (json['files_count'] as num?)?.toInt(),
      foldersCount: (json['folders_count'] as num?)?.toInt(),
      totalSize: (json['total_size'] as num?)?.toInt(),
      path: json['path'] as String?,
    );
  }

  static DateTime? _parseDate(dynamic v) {
    if (v is String && v.isNotEmpty) {
      return DateTime.tryParse(v);
    }
    return null;
  }
}

enum RecentEntryType { folder, file }
