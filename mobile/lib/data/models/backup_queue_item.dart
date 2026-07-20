/// State sebuah file di queue Auto Backup. Mutable via [BackupQueueItem.copyWith]
/// saat upload progress berubah. Immutable record pattern biar aman di-share
/// ke background isolate (workmanager).
enum BackupItemStatus { pending, hashing, uploading, done, skipped, failed }

BackupItemStatus parseBackupItemStatus(String s) {
  switch (s) {
    case 'hashing':
      return BackupItemStatus.hashing;
    case 'uploading':
      return BackupItemStatus.uploading;
    case 'done':
      return BackupItemStatus.done;
    case 'skipped':
      return BackupItemStatus.skipped;
    case 'failed':
      return BackupItemStatus.failed;
    case 'pending':
    default:
      return BackupItemStatus.pending;
  }
}

/// Satu file di queue backup.
///
/// `localAssetId` adalah ID dari photo_manager (untuk Android MediaStore
/// row / iOS PHAsset localIdentifier). `absolutePath` adalah path lokal
/// untuk baca file (butuh permission runtime). `contentHash` adalah
/// SHA-256 konten, dihitung sekali lalu di-skip kalau server sudah punya.
///
/// `targetRelativePath` adalah path relatif dari root EnStorage, mis.
/// `DCIM/Camera/IMG_001.jpg`. Folder tujuan dibuat server-side otomatis
/// oleh `BackupRepository.ensureFolderPath`.
class BackupQueueItem {
  const BackupQueueItem({
    required this.localAssetId,
    required this.absolutePath,
    required this.displayName,
    required this.sizeBytes,
    required this.modifiedAtMs,
    required this.status,
    this.contentHash,
    this.progressBytes = 0,
    this.errorMessage,
    this.remoteFileId,
    this.targetFolderId,
    this.targetRelativePath,
  });

  final String localAssetId;
  final String absolutePath;
  final String displayName;
  final int sizeBytes;
  final int modifiedAtMs;

  final BackupItemStatus status;
  final String? contentHash;
  final int progressBytes;
  final String? errorMessage;
  final String? remoteFileId;
  final String? targetFolderId;
  final String? targetRelativePath;

  /// Fraction 0.0–1.0 untuk progress bar per-file. Return 0 kalau sizeBytes
  /// tidak diketahui (0). Capped di 1.0 untuk defensive (over-reporting).
  double get progressFraction {
    if (sizeBytes <= 0) return 0.0;
    final f = progressBytes / sizeBytes;
    return f > 1.0 ? 1.0 : f;
  }

  bool get isTerminal =>
      status == BackupItemStatus.done ||
      status == BackupItemStatus.skipped ||
      status == BackupItemStatus.failed;

  BackupQueueItem copyWith({
    BackupItemStatus? status,
    String? contentHash,
    int? progressBytes,
    String? errorMessage,
    bool clearErrorMessage = false,
    String? remoteFileId,
    String? targetFolderId,
    String? targetRelativePath,
  }) {
    return BackupQueueItem(
      localAssetId: localAssetId,
      absolutePath: absolutePath,
      displayName: displayName,
      sizeBytes: sizeBytes,
      modifiedAtMs: modifiedAtMs,
      status: status ?? this.status,
      contentHash: contentHash ?? this.contentHash,
      progressBytes: progressBytes ?? this.progressBytes,
      errorMessage:
          clearErrorMessage ? null : (errorMessage ?? this.errorMessage),
      remoteFileId: remoteFileId ?? this.remoteFileId,
      targetFolderId: targetFolderId ?? this.targetFolderId,
      targetRelativePath: targetRelativePath ?? this.targetRelativePath,
    );
  }

  Map<String, Object?> toJson() => {
        'local_asset_id': localAssetId,
        'absolute_path': absolutePath,
        'display_name': displayName,
        'size_bytes': sizeBytes,
        'modified_at_ms': modifiedAtMs,
        'status': status.name,
        'content_hash': contentHash,
        'progress_bytes': progressBytes,
        'error_message': errorMessage,
        'remote_file_id': remoteFileId,
        'target_folder_id': targetFolderId,
        'target_relative_path': targetRelativePath,
      };

  factory BackupQueueItem.fromJson(Map<String, Object?> json) => BackupQueueItem(
        localAssetId: (json['local_asset_id'] ?? '') as String,
        absolutePath: (json['absolute_path'] ?? '') as String,
        displayName: (json['display_name'] ?? '') as String,
        sizeBytes: (json['size_bytes'] ?? 0) as int,
        modifiedAtMs: (json['modified_at_ms'] ?? 0) as int,
        status: parseBackupItemStatus((json['status'] ?? 'pending') as String),
        contentHash: json['content_hash'] as String?,
        progressBytes: (json['progress_bytes'] ?? 0) as int,
        errorMessage: json['error_message'] as String?,
        remoteFileId: json['remote_file_id'] as String?,
        targetFolderId: json['target_folder_id'] as String?,
        targetRelativePath: json['target_relative_path'] as String?,
      );
}