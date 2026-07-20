import 'dart:async';
import 'package:flutter_background_service/flutter_background_service.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/models/backup_progress.dart';
import '../data/models/backup_queue_item.dart';
import '../data/models/backup_settings.dart';
import '../data/repositories/backup_repository.dart';
import '../data/repositories/backup_settings_repository.dart';
import '../data/repositories/backup_queue_repository.dart';
import '../services/backup_worker.dart';

ProviderContainer? backupAppContainer;

void setBackupAppContainer(ProviderContainer container) {
  backupAppContainer = container;
}

class BackupController extends StateNotifier<BackupProgress> {
  BackupController(this._settingsRepo, this._backupRepo, this._queueRepo)
      : super(BackupProgress.initial) {
    _load();
    _listenToDb();
    _listenToBackgroundService();
  }

  final BackupSettingsRepository _settingsRepo;
  final BackupRepository _backupRepo;
  final BackupQueueRepository _queueRepo;
  StreamSubscription<List<BackupQueueItem>>? _dbSub;
  StreamSubscription<Map<String, dynamic>?>? _serviceSub;

  void _load() {
    final s = _settingsRepo.read();
    state = state.copyWith(settings: s);
  }

  void _listenToDb() {
    _dbSub?.cancel();
    _dbSub = _queueRepo.watchAll().listen((items) {
      final totals = _calcTotals(items);
      state = state.copyWith(queue: items, totals: totals);
    });
  }

  void _listenToBackgroundService() {
    _serviceSub?.cancel();

    // Dengarkan update state dari foreground service isolate
    _serviceSub = FlutterBackgroundService().on('state').listen((event) {
      if (event != null && mounted) {
        state = state.copyWith(
          isRunning: event['isRunning'] ?? false,
          paused: event['paused'] ?? false,
        );
      }
    });

    // Dengarkan trigger update database dari foreground service isolate
    FlutterBackgroundService().on('db_updated').listen((_) {
      if (mounted) {
        _load();
        _listenToDb();
      }
    });

    // Tanya status aktif service saat UI pertama kali di-bind
    FlutterBackgroundService().isRunning().then((running) {
      if (running) {
        FlutterBackgroundService().invoke('action', {'type': 'query_status'});
      }
    });
  }

  @override
  void dispose() {
    _dbSub?.cancel();
    _serviceSub?.cancel();
    super.dispose();
  }

  // ─── Settings mutations ─────────────────────────────────────────

  Future<void> setEnabled(bool enabled) async {
    final s = state.settings.copyWith(enabled: enabled);
    await _settingsRepo.write(s);
    state = state.copyWith(settings: s);

    if (enabled) {
      final hours = backupIntervalHours[s.interval] ?? 6;
      await scheduleBackup(hours, requiresCharging: s.requiresCharging);
    } else {
      await cancelBackup();
    }
  }

  Future<void> setMode(BackupNetworkMode mode) async {
    final s = state.settings.copyWith(mode: mode);
    await _settingsRepo.write(s);
    state = state.copyWith(settings: s);
  }

  Future<void> setInterval(BackupInterval interval) async {
    final s = state.settings.copyWith(interval: interval);
    await _settingsRepo.write(s);
    state = state.copyWith(settings: s);

    if (s.enabled) {
      final hours = backupIntervalHours[interval] ?? 6;
      await scheduleBackup(hours, requiresCharging: s.requiresCharging);
    }
  }

  Future<void> setRequiresCharging(bool requiresCharging) async {
    final s = state.settings.copyWith(requiresCharging: requiresCharging);
    await _settingsRepo.write(s);
    state = state.copyWith(settings: s);

    if (s.enabled) {
      final hours = backupIntervalHours[s.interval] ?? 6;
      await scheduleBackup(hours, requiresCharging: requiresCharging);
    }
  }

  Future<void> setBatteryOptimizationExempted(bool exempted) async {
    final s = state.settings.copyWith(batteryOptimizationExempted: exempted);
    await _settingsRepo.write(s);
    state = state.copyWith(settings: s);
  }

  Future<void> recordLastRun({String? error}) async {
    final s = state.settings.copyWith(
      lastRunAt: DateTime.now(),
      lastError: error,
    );
    await _settingsRepo.write(s);
    state = state.copyWith(settings: s);
  }

  Future<void> clearError() async {
    final s = state.settings.copyWith(clearLastError: true);
    await _settingsRepo.write(s);
    state = state.copyWith(settings: s);
  }

  // ─── Run state mutations (called by service + worker) ───────────

  void runStarted() {
    final s = state.settings.copyWith(clearLastError: true);
    _settingsRepo.write(s);
    state = state.copyWith(settings: s, isRunning: true, paused: false);
  }

  void runPaused() {
    state = state.copyWith(paused: true);
  }

  void runResumed() {
    state = state.copyWith(paused: false);
  }

  void runCancelled() {
    state = BackupProgress(
      settings: state.settings,
      queue: const [],
      totals: BackupTotals.empty,
      isRunning: false,
      paused: false,
    );
  }

  void runFinished() {
    state = state.copyWith(isRunning: false, paused: false);
  }

  // ─── Queue mutations ────────────────────────────────────────────

  void replaceQueue(List<BackupQueueItem> queue) {
    final totals = _calcTotals(queue);
    state = state.copyWith(queue: queue, totals: totals);
  }

  void updateItem(String localAssetId, BackupQueueItem Function(BackupQueueItem) update) {
    final item = state.queue.firstWhere((it) => it.localAssetId == localAssetId);
    final updated = update(item);
    _queueRepo.updateItem(updated);
  }

  void markUploadProgress(String localAssetId, int sentBytes) {
    updateItem(localAssetId, (it) =>
        it.copyWith(progressBytes: sentBytes, status: BackupItemStatus.uploading));
  }

  void markItemDone(String localAssetId, String remoteFileId) {
    updateItem(localAssetId, (it) => it.copyWith(
          status: BackupItemStatus.done,
          progressBytes: it.sizeBytes,
          remoteFileId: remoteFileId,
          clearErrorMessage: true,
        ));
  }

  void markItemSkipped(String localAssetId) {
    updateItem(localAssetId, (it) =>
        it.copyWith(status: BackupItemStatus.skipped, clearErrorMessage: true));
  }

  void markItemFailed(String localAssetId, String message) {
    updateItem(localAssetId, (it) =>
        it.copyWith(status: BackupItemStatus.failed, errorMessage: message));
  }

  static BackupTotals _calcTotals(List<BackupQueueItem> queue) {
    var done = 0;
    var inProgress = 0;
    var pending = 0;
    var failed = 0;
    var skipped = 0;
    var doneBytes = 0;
    var totalBytes = 0;
    for (final it in queue) {
      totalBytes += it.sizeBytes;
      switch (it.status) {
        case BackupItemStatus.pending:
          pending++;
          break;
        case BackupItemStatus.hashing:
        case BackupItemStatus.uploading:
          inProgress++;
          break;
        case BackupItemStatus.done:
          done++;
          doneBytes += it.sizeBytes;
          break;
        case BackupItemStatus.skipped:
          skipped++;
          doneBytes += it.sizeBytes;
          break;
        case BackupItemStatus.failed:
          failed++;
          break;
      }
    }
    return BackupTotals(
      done: done,
      inProgress: inProgress,
      pending: pending,
      failed: failed,
      skipped: skipped,
      doneBytes: doneBytes,
      totalBytes: totalBytes,
    );
  }
}

final backupControllerProvider =
    StateNotifierProvider<BackupController, BackupProgress>((ref) {
  return BackupController(
    ref.watch(backupSettingsRepositoryProvider),
    ref.watch(backupRepositoryProvider),
    ref.watch(backupQueueRepositoryProvider),
  );
});
