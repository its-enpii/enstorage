import 'backup_queue_item.dart';
import 'backup_settings.dart';

/// Aggregated progress state Auto Backup. Ditulis ke Riverpod StateNotifier
/// dan dibaca oleh settings screen + progress screen.
class BackupProgress {
  const BackupProgress({
    required this.settings,
    required this.queue,
    required this.totals,
    required this.isRunning,
    required this.paused,
  });

  final BackupSettings settings;
  final List<BackupQueueItem> queue;
  final BackupTotals totals;
  final bool isRunning;
  final bool paused;

  static const initial = BackupProgress(
    settings: BackupSettings.initial,
    queue: [],
    totals: BackupTotals.empty,
    isRunning: false,
    paused: false,
  );

  /// Fraction 0.0–1.0 untuk progress bar overall. Hitung dari hitungan
  /// file yang sudah terminal (done/skipped/failed) + kontribusi parsial
  /// file yang sedang upload (bytes sent / size). Match dengan counter
  /// "X of Y files done" di header.
  double get overallFraction {
    final total = totals.done + totals.skipped + totals.failed +
        totals.inProgress + totals.pending;
    if (total <= 0) return 0.0;
    var completed = totals.done + totals.skipped + totals.failed;
    var inProgress = 0.0;
    for (final item in queue) {
      if (item.status == BackupItemStatus.uploading) {
        inProgress += item.sizeBytes > 0
            ? (item.progressBytes / item.sizeBytes).clamp(0.0, 1.0)
            : 0.0;
      } else if (item.status == BackupItemStatus.hashing) {
        // Sedang hashing: anggap setengah (proses ringan, cepat).
        inProgress += 0.0;
      }
    }
    final f = (completed + inProgress) / total;
    return f > 1.0 ? 1.0 : f;
  }

  BackupProgress copyWith({
    BackupSettings? settings,
    List<BackupQueueItem>? queue,
    BackupTotals? totals,
    bool? isRunning,
    bool? paused,
  }) {
    return BackupProgress(
      settings: settings ?? this.settings,
      queue: queue ?? this.queue,
      totals: totals ?? this.totals,
      isRunning: isRunning ?? this.isRunning,
      paused: paused ?? this.paused,
    );
  }
}

class BackupTotals {
  const BackupTotals({
    required this.done,
    required this.inProgress,
    required this.pending,
    required this.failed,
    required this.skipped,
    required this.doneBytes,
    required this.totalBytes,
  });

  final int done;
  final int inProgress;
  final int pending;
  final int failed;
  final int skipped;
  final int doneBytes;
  final int totalBytes;

  static const empty = BackupTotals(
    done: 0,
    inProgress: 0,
    pending: 0,
    failed: 0,
    skipped: 0,
    doneBytes: 0,
    totalBytes: 0,
  );

  BackupTotals copyWith({
    int? done,
    int? inProgress,
    int? pending,
    int? failed,
    int? skipped,
    int? doneBytes,
    int? totalBytes,
  }) =>
      BackupTotals(
        done: done ?? this.done,
        inProgress: inProgress ?? this.inProgress,
        pending: pending ?? this.pending,
        failed: failed ?? this.failed,
        skipped: skipped ?? this.skipped,
        doneBytes: doneBytes ?? this.doneBytes,
        totalBytes: totalBytes ?? this.totalBytes,
      );
}