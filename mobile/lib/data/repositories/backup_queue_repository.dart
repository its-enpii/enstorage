import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../db/backup_database.dart';
import '../models/backup_queue_item.dart';

class BackupQueueRepository {
  BackupQueueRepository(this._db);

  final BackupDatabase _db;

  /// Convert Drift Entity to UI Model
  BackupQueueItem _toModel(BackupQueueItemEntry entry) {
    return BackupQueueItem(
      localAssetId: entry.localAssetId,
      absolutePath: entry.absolutePath,
      displayName: entry.displayName,
      sizeBytes: entry.sizeBytes,
      modifiedAtMs: entry.modifiedAtMs,
      status: parseBackupItemStatus(entry.status),
      contentHash: entry.contentHash,
      progressBytes: entry.progressBytes,
      errorMessage: entry.errorMessage,
      remoteFileId: entry.remoteFileId,
      targetRelativePath: entry.targetRelativePath,
    );
  }

  /// Convert UI Model to Drift Companion
  BackupQueueItemsCompanion _toCompanion(BackupQueueItem model) {
    return BackupQueueItemsCompanion(
      localAssetId: Value(model.localAssetId),
      absolutePath: Value(model.absolutePath),
      displayName: Value(model.displayName),
      sizeBytes: Value(model.sizeBytes),
      modifiedAtMs: Value(model.modifiedAtMs),
      status: Value(model.status.name),
      contentHash: Value(model.contentHash),
      progressBytes: Value(model.progressBytes),
      errorMessage: Value(model.errorMessage),
      remoteFileId: Value(model.remoteFileId),
      targetRelativePath: Value(model.targetRelativePath),
    );
  }

  /// Ambil semua item antrian backup
  Future<List<BackupQueueItem>> getAll() async {
    final entries = await _db.select(_db.backupQueueItems).get();
    return entries.map(_toModel).toList();
  }

  /// Ambil semua item secara reaktif via Stream
  Stream<List<BackupQueueItem>> watchAll() {
    return _db
        .select(_db.backupQueueItems)
        .watch()
        .map((entries) => entries.map(_toModel).toList());
  }

  /// Ambil semua item yang belum diproses (pending, failed, hashing, uploading)
  Future<List<BackupQueueItem>> getPendingAndFailed() async {
    final query = _db.select(_db.backupQueueItems)
      ..where((t) => t.status.isIn(['pending', 'failed', 'hashing', 'uploading']));
    final entries = await query.get();
    return entries.map(_toModel).toList();
  }

  /// Simpan banyak item sekaligus (insert or replace)
  Future<void> saveAll(List<BackupQueueItem> items) async {
    await _db.batch((batch) {
      batch.insertAll(
        _db.backupQueueItems,
        items.map((it) => _toCompanion(it).copyWith(
          // Ensure primary key is bound correctly
          localAssetId: Value(it.localAssetId),
        )),
        mode: InsertMode.insertOrReplace,
      );
    });
  }

  /// Update status dan field item tunggal secara atomic
  Future<void> updateItem(BackupQueueItem item) async {
    await (_db.update(_db.backupQueueItems)
          ..where((t) => t.localAssetId.equals(item.localAssetId)))
        .write(_toCompanion(item));
  }

  /// Hapus seluruh antrian
  Future<void> clearQueue() async {
    await _db.delete(_db.backupQueueItems).go();
  }

  /// Ambil log backup run terakhir
  Future<BackupRunLogEntry?> getLastLog() async {
    final query = _db.select(_db.backupRunLogs)
      ..orderBy([(t) => OrderingTerm.desc(t.startedAt)])
      ..limit(1);
    return query.getSingleOrNull();
  }

  /// Tambah log backup run baru
  Future<int> insertLog(BackupRunLogEntry log) async {
    return _db.into(_db.backupRunLogs).insert(
          BackupRunLogsCompanion.insert(
            runId: log.runId,
            startedAt: log.startedAt,
            endedAt: Value(log.endedAt),
            mode: log.mode,
            doneCount: log.doneCount,
            failedCount: log.failedCount,
            skippedCount: log.skippedCount,
            totalBytes: log.totalBytes,
          ),
        );
  }

  /// Selesaikan status log backup run
  Future<void> updateLogEnd(String runId, {
    required int done,
    required int failed,
    required int skipped,
  }) async {
    final query = _db.update(_db.backupRunLogs)
      ..where((t) => t.runId.equals(runId));
    await query.write(
      BackupRunLogsCompanion(
        endedAt: Value(DateTime.now()),
        doneCount: Value(done),
        failedCount: Value(failed),
        skippedCount: Value(skipped),
      ),
    );
  }
}

final backupQueueRepositoryProvider = Provider<BackupQueueRepository>((ref) {
  return BackupQueueRepository(ref.watch(backupDatabaseProvider));
});
