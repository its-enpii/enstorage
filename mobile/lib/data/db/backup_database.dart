import 'dart:io';
import 'package:drift/drift.dart';
import 'package:drift_flutter/drift_flutter.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

part 'backup_database.g.dart';

/// Table schema untuk list file antrian backup (Drift)
@DataClassName('BackupQueueItemEntry')
class BackupQueueItems extends Table {
  TextColumn get localAssetId => text()();
  TextColumn get absolutePath => text()();
  TextColumn get displayName => text()();
  IntColumn get sizeBytes => integer()();
  IntColumn get modifiedAtMs => integer()();
  TextColumn get status => text()(); // pending, hashing, uploading, done, skipped, failed
  IntColumn get progressBytes => integer()();
  TextColumn get errorMessage => text().nullable()();
  TextColumn get remoteFileId => text().nullable()();
  TextColumn get contentHash => text().nullable()();
  TextColumn get targetRelativePath => text().nullable()();

  @override
  Set<Column> get primaryKey => {localAssetId};
}

/// Table schema untuk log eksekusi backup run
@DataClassName('BackupRunLogEntry')
class BackupRunLogs extends Table {
  IntColumn get id => integer().autoIncrement()();
  TextColumn get runId => text()();
  DateTimeColumn get startedAt => dateTime()();
  DateTimeColumn get endedAt => dateTime().nullable()();
  TextColumn get mode => text()(); // manual, periodic
  IntColumn get doneCount => integer()();
  IntColumn get failedCount => integer()();
  IntColumn get skippedCount => integer()();
  IntColumn get totalBytes => integer()();
}

/// Singleton container untuk database instance agar aman diakses dari multiple isolates
BackupDatabase? _backupDatabaseInstance;

/// Helper top-level untuk resolve DB di background worker (WorkManager) yang tidak
/// punya akses ke provider container asli main UI.
BackupDatabase getBackupDatabase() {
  return _backupDatabaseInstance ??= BackupDatabase();
}

@DriftDatabase(tables: [BackupQueueItems, BackupRunLogs])
class BackupDatabase extends _$BackupDatabase {
  BackupDatabase() : super(_openConnection());

  @override
  int get schemaVersion => 1;

  static QueryExecutor _openConnection() {
    return driftDatabase(
      name: 'enstorage_backup',
      native: DriftNativeOptions(
        databaseDirectory: () async {
          final dir = await getApplicationDocumentsDirectory();
          return p.join(dir.path, 'databases');
        },
        setup: (db) {
          // Aktifkan WAL mode + busy timeout untuk menangani akses concurrent
          // dari background isolate (WorkManager) dan main UI isolate.
          db.execute('PRAGMA journal_mode=WAL;');
          db.execute('PRAGMA busy_timeout=5000;');
        },
      ),
    );
  }
}

/// Riverpod provider untuk database di UI layer
final backupDatabaseProvider = Provider<BackupDatabase>((ref) {
  final db = getBackupDatabase();
  ref.onDispose(() => db.close());
  return db;
});
