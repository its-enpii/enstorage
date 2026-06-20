/// Mirrors backend `Folder` resource.
class Folder {
  const Folder({
    required this.id,
    required this.name,
    required this.parentId,
    required this.isStarred,
    this.filesCount = 0,
    this.foldersCount = 0,
    this.totalSize = 0,
  });

  final String id;
  final String name;
  final String? parentId;
  final bool isStarred;
  final int filesCount;
  final int foldersCount;
  final int totalSize;

  factory Folder.fromJson(Map<String, dynamic> json) => Folder(
        id: (json['id'] ?? '').toString(),
        name: (json['name'] ?? '') as String,
        parentId: json['parent_id']?.toString(),
        isStarred: (json['is_starred'] ?? false) as bool,
        filesCount: (json['files_count'] ?? 0) as int,
        foldersCount: (json['folders_count'] ?? 0) as int,
        totalSize: (json['total_size'] ?? 0) as int,
      );

  Folder copyWith({String? name, bool? isStarred, String? parentId, int? filesCount, int? foldersCount, int? totalSize}) {
    return Folder(
      id: id,
      name: name ?? this.name,
      parentId: parentId ?? this.parentId,
      isStarred: isStarred ?? this.isStarred,
      filesCount: filesCount ?? this.filesCount,
      foldersCount: foldersCount ?? this.foldersCount,
      totalSize: totalSize ?? this.totalSize,
    );
  }
}
