import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:crypto/crypto.dart';
import 'package:flutter_background_service/flutter_background_service.dart';
import 'package:flutter_background_service_android/flutter_background_service_android.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:photo_manager/photo_manager.dart';

import '../data/models/backup_queue_item.dart';
import '../data/models/backup_settings.dart';
import '../data/repositories/backup_repository.dart';
import '../data/repositories/backup_settings_repository.dart';
import '../data/repositories/backup_queue_repository.dart';
import '../data/storage/token_storage.dart';
import '../state/backup_state.dart';
import 'backup_worker.dart';

enum _PausedState { notPaused, paused }

/// Auto Backup orchestrator — backed by Drift SQLite database & FlutterBackgroundService.
class BackupService {
  BackupService({
    required this.settingsRepo,
    required this.backupRepo,
    required this.queueRepo,
    required this.tokenStorage,
  });

  final BackupSettingsRepository settingsRepo;
  final BackupRepository backupRepo;
  final BackupQueueRepository queueRepo;
  final TokenStorage tokenStorage;

  final _localNotifs = FlutterLocalNotificationsPlugin();

  bool _cancelled = false;
  _PausedState _pauseCtrl = _PausedState.notPaused;
  Completer<void>? _pauseCompleter;

  bool get isPaused => _pauseCtrl == _PausedState.paused;

  /// Penentu isolate eksekusi.
  /// Jika backgroundServiceInstance == null, artinya code berjalan di main UI Isolate.
  bool get _isMainIsolate => backgroundServiceInstance == null;

  /// Entry point untuk UI ("Run now" manual).
  Future<void> runOnce({bool triggeredByUserTap = false}) async {
    if (_isMainIsolate) {
      // Jika dipanggil dari UI, trigger foreground service untuk jalan
      final service = FlutterBackgroundService();
      if (!await service.isRunning()) {
        await service.startService();
      }
    } else {
      await runPipeline(isBackground: false, triggeredByUserTap: triggeredByUserTap);
    }
  }

  /// Entry point untuk background task (WorkManager).
  Future<void> runBackground() async {
    if (_isMainIsolate) {
      final service = FlutterBackgroundService();
      if (!await service.isRunning()) {
        await service.startService();
      }
    } else {
      await runPipeline(isBackground: true, triggeredByUserTap: false);
    }
  }

  /// Menghentikan backup.
  Future<void> cancel() async {
    if (_isMainIsolate) {
      // Kirim event cancel ke service isolate
      FlutterBackgroundService().invoke('action', {'type': 'cancel'});
    } else {
      _cancelled = true;
      _pauseCtrl = _PausedState.notPaused;
      _unblockPauseGate();
      await _cancelOngoingNotification();
      await queueRepo.clearQueue();
      _emitStateChange(isRunning: false, paused: false);
      _emitDbUpdated();
    }
  }

  /// Menjeda backup.
  Future<void> pause() async {
    if (_isMainIsolate) {
      // Kirim event pause ke service isolate
      FlutterBackgroundService().invoke('action', {'type': 'pause'});
    } else {
      _pauseCtrl = _PausedState.paused;
      await _updateOngoingNotificationPaused();
      _emitStateChange(isRunning: true, paused: true);
    }
  }

  /// Melanjutkan backup yang dijeda.
  Future<void> resume() async {
    if (_isMainIsolate) {
      // Kirim event resume ke service isolate
      FlutterBackgroundService().invoke('action', {'type': 'resume'});
    } else {
      _pauseCtrl = _PausedState.notPaused;
      _unblockPauseGate();
      await _updateOngoingNotificationResumed();
      _emitStateChange(isRunning: true, paused: false);
    }
  }

  // Helper untuk memicu reload DB di main isolate
  void _emitDbUpdated() {
    backgroundServiceInstance?.invoke('db_updated');
  }

  // Helper untuk memberi tahu state perubahan ke main isolate
  void _emitStateChange({required bool isRunning, required bool paused}) {
    backgroundServiceInstance?.invoke('state', {
      'isRunning': isRunning,
      'paused': paused,
    });
  }

  /// Main execution pipeline.
  Future<void> runPipeline({required bool isBackground, bool triggeredByUserTap = false}) async {
    final settings = settingsRepo.read();
    if (!settings.enabled) {
      if (triggeredByUserTap) await _cancelOngoingNotification();
      return;
    }
    if (settings.mode == BackupNetworkMode.off) {
      if (triggeredByUserTap) await _showOfflineNotification();
      return;
    }

    final connectivity = await Connectivity().checkConnectivity();
    if (!_matchesMode(connectivity, settings.mode)) {
      if (triggeredByUserTap) await _showOfflineNotification();
      return;
    }

    // Android background isolate ignore permission check helper.
    if (isBackground) {
      PhotoManager.setIgnorePermissionCheck(true);
    } else {
      final photoPerm = await PhotoManager.requestPermissionExtend();
      if (!photoPerm.isAuth && photoPerm != PermissionState.limited) {
        await _showPermissionDeniedNotification();
        return;
      }
    }

    final token = await tokenStorage.readToken();
    if (token == null) {
      await _showAuthMissingNotification();
      return;
    }

    // Bersihkan error lama di settings sebelum running
    await settingsRepo.write(settings.copyWith(clearLastError: true));
    _emitDbUpdated();

    _cancelled = false;
    _emitStateChange(isRunning: true, paused: false);

    final stopwatch = Stopwatch()..start();
    final runId = 'run_${DateTime.now().millisecondsSinceEpoch}';

    try {
      await _showOngoingNotification(progress: 0, currentFile: 'Memindai galeri…');

      // Ambil queue yang belum selesai dari DB
      var queue = await queueRepo.getPendingAndFailed();

      if (queue.isEmpty) {
        // Scan media gallery jika tidak ada queue tersisa di DB
        final scannedItems = await _scan();
        if (scannedItems.isEmpty) {
          await _finishOngoingNotification(done: true, total: 0, uploaded: 0);
          await settingsRepo.write(settings.copyWith(lastRunAt: DateTime.now()));
          _emitDbUpdated();
          return;
        }

        // Simpan ke DB dengan mapping path target
        final prepared = scannedItems.map((it) {
          final segments = _devicePathSegments(it);
          return it.copyWith(targetRelativePath: segments.join('/'));
        }).toList();

        await queueRepo.saveAll(prepared);
        queue = prepared;
        _emitDbUpdated();
      }

      // Phase 1: Bulk metadata pre-check untuk file status 'pending'
      final pendingItems = queue.where((it) => it.status == BackupItemStatus.pending).toList();
      if (pendingItems.isNotEmpty) {
        final metadataItems = pendingItems
            .map((it) => (
                  path: it.absolutePath,
                  mtimeMs: it.modifiedAtMs,
                  size: it.sizeBytes,
                ))
            .toList();

        final matchedByKey = <String, ExistingFileRef>{};
        try {
          final res = await backupRepo.checkExistingByMetadata(metadataItems);
          matchedByKey.addAll(res);
        } catch (_) {
          // Fallback network blip
        }

        // Apply metadata matching di SQLite
        for (final item in pendingItems) {
          final metaKey = '${item.absolutePath}|${item.modifiedAtMs}|${item.sizeBytes}';
          final existing = matchedByKey[metaKey];
          if (existing != null) {
            final updated = item.copyWith(
              status: BackupItemStatus.skipped,
              remoteFileId: existing.fileId,
            );
            await queueRepo.updateItem(updated);
          }
        }
        _emitDbUpdated();
      }

      if (_cancelled) return;

      // Ambil sisa queue ter-update untuk proses upload
      queue = await queueRepo.getPendingAndFailed();
      final totalFiles = (await queueRepo.getAll()).length;

      var uploaded = 0;
      var failed = 0;
      var skipped = 0;

      // Phase 2: Sequential upload
      for (var i = 0; i < queue.length; i++) {
        if (_cancelled) break;
        await _pauseGate();

        // Android background watchdog (max 8.5 menit)
        if (isBackground && stopwatch.elapsed.inSeconds > 510) {
          // Trigger one-off chain task untuk melanjuti sisa queue
          await registerOneOffBackupTask();
          break;
        }

        var item = queue[i];
        if (item.status == BackupItemStatus.skipped) {
          skipped++;
          continue;
        }

        // Hashing file
        await queueRepo.updateItem(item.copyWith(status: BackupItemStatus.hashing));
        _emitDbUpdated();

        String hash;
        try {
          hash = await _hashFile(item);
          item = item.copyWith(contentHash: hash);
          await queueRepo.updateItem(item);
        } catch (e) {
          final err = 'Hash gagal: ${e.toString().split('\n').first}';
          await queueRepo.updateItem(item.copyWith(status: BackupItemStatus.failed, errorMessage: err));
          _emitDbUpdated();
          failed++;
          continue;
        }

        if (_cancelled) return;

        // Uploading file
        await queueRepo.updateItem(item.copyWith(status: BackupItemStatus.uploading, progressBytes: 0));
        _emitDbUpdated();

        try {
          final folderSegs = _devicePathSegments(item);
          if (folderSegs.length > 1) {
            folderSegs.removeLast();
          } else {
            folderSegs.clear();
          }

          final folderId = await backupRepo.ensureFolderPath(null, folderSegs);
          final clientKey = 'auto:${DateTime.now().microsecondsSinceEpoch}:${_stableClientKey(item)}';

          final updated = await backupRepo.uploadWithHash(
            item.copyWith(targetFolderId: folderId, contentHash: hash),
            folderId: folderId!,
            clientKey: clientKey,
            onProgress: (sent, total) async {
              await queueRepo.updateItem(item.copyWith(status: BackupItemStatus.uploading, progressBytes: sent));
              _emitDbUpdated();
            },
          );

          final remoteId = updated.remoteFileId;
          if (remoteId == null || remoteId.isEmpty) {
            const err = 'Server tidak mengembalikan ID file';
            await queueRepo.updateItem(item.copyWith(status: BackupItemStatus.failed, errorMessage: err));
            _emitDbUpdated();
            failed++;
            continue;
          }

          // Polling server worker queue
          final outcome = await _waitForWorkerDone(remoteId);
          if (outcome == 'done') {
            await queueRepo.updateItem(item.copyWith(status: BackupItemStatus.done, remoteFileId: remoteId));
            _emitDbUpdated();
            uploaded++;
          } else {
            final reason = switch (outcome) {
              'failed' => 'Gagal di server',
              'cancelled' => 'Dibatalkan',
              _ => 'Worker timeout (60 detik)',
            };
            await queueRepo.updateItem(item.copyWith(status: BackupItemStatus.failed, errorMessage: reason));
            _emitDbUpdated();
            failed++;
          }
        } catch (e) {
          final msg = e.toString().split('\n').first;
          await queueRepo.updateItem(item.copyWith(status: BackupItemStatus.failed, errorMessage: msg));
          _emitDbUpdated();
          failed++;
        }

        // Update progress notif
        final currentProgress = await queueRepo.getAll();
        final processedCount = currentProgress.where((it) => it.isTerminal).length;
        await _showOngoingNotification(
          progress: totalFiles == 0 ? 0 : (processedCount * 100) ~/ totalFiles,
          currentFile: item.displayName,
        );
      }

      // Final logs & notification
      final finalProgress = await queueRepo.getAll();
      final doneFiles = finalProgress.where((it) => it.status == BackupItemStatus.done).length;
      final failedFiles = finalProgress.where((it) => it.status == BackupItemStatus.failed).length;

      final remaining = finalProgress.where((it) => !it.isTerminal).length;

      // Jika queue habis, hapus dari database agar bersih
      if (remaining == 0) {
        await queueRepo.clearQueue();
      }

      await _finishOngoingNotification(
        done: !_cancelled,
        total: totalFiles,
        uploaded: doneFiles,
        failed: failedFiles,
      );

      await settingsRepo.write(settings.copyWith(
        lastRunAt: DateTime.now(),
        lastError: _cancelled ? 'cancelled' : null,
      ));
      _emitDbUpdated();
    } catch (e) {
      await _finishOngoingNotification(
        done: false,
        total: 0,
        uploaded: 0,
        error: e.toString(),
      );
      await settingsRepo.write(settings.copyWith(
        lastRunAt: DateTime.now(),
        lastError: e.toString().split('\n').first,
      ));
      _emitDbUpdated();
    } finally {
      stopwatch.stop();
    }
  }

  // ─── Internal pause gate ──────────────────────────────────────────

  Future<void> _pauseGate() async {
    while (_pauseCtrl == _PausedState.paused && !_cancelled) {
      _pauseCompleter ??= Completer<void>();
      await _pauseCompleter!.future;
    }
  }

  Future<String> _waitForWorkerDone(String remoteFileId) async {
    const maxWait = Duration(seconds: 60);
    const interval = Duration(milliseconds: 1500);
    final deadline = DateTime.now().add(maxWait);
    while (DateTime.now().isBefore(deadline) && !_cancelled) {
      await _pauseGate();
      final status = await backupRepo.pollFileStatus(remoteFileId);
      if (status == 'done') return 'done';
      if (status == 'failed') return 'failed';
      await Future<void>.delayed(interval);
    }
    return _cancelled ? 'cancelled' : 'timeout';
  }

  void _unblockPauseGate() {
    final c = _pauseCompleter;
    if (c != null && !c.isCompleted) c.complete();
    _pauseCompleter = null;
  }

  // ─── Notification Helpers ─────────────────────────────────────────

  static const _channelId = 'enstorage_backup_progress';
  static const _notifId = 9100;

  Future<void> _ensureChannel() async {
    const channel = AndroidNotificationChannel(
      _channelId,
      'Auto Backup',
      description: 'Auto backup progress',
      importance: Importance.defaultImportance,
      playSound: false,
      enableVibration: false,
      showBadge: false,
    );
    final androidImpl = _localNotifs.resolvePlatformSpecificImplementation<
        AndroidFlutterLocalNotificationsPlugin>();
    await androidImpl?.createNotificationChannel(channel);
  }

  Future<void> _showOngoingNotification({
    required int progress,
    required String currentFile,
  }) async {
    await _ensureChannel();
    final title = 'Auto Backup';
    final content = '$currentFile • $progress%';

    // Jika berjalan di foreground service isolate, update notif service secara native
    final nativeService = backgroundServiceInstance;
    if (nativeService is AndroidServiceInstance) {
      await nativeService.setForegroundNotificationInfo(
        title: title,
        content: content,
      );
    }

    await _localNotifs.show(
      id: _notifId,
      title: title,
      body: content,
      notificationDetails: NotificationDetails(
        android: AndroidNotificationDetails(
          _channelId,
          'Auto Backup',
          channelDescription: 'Auto backup progress',
          importance: Importance.defaultImportance,
          priority: Priority.defaultPriority,
          icon: '@drawable/ic_launcher',
          showProgress: true,
          maxProgress: 100,
          progress: progress.clamp(0, 100),
          indeterminate: false,
          ongoing: true,
          autoCancel: false,
          onlyAlertOnce: true,
          enableVibration: false,
          playSound: false,
          showWhen: false,
          actions: <AndroidNotificationAction>[
            const AndroidNotificationAction('backup_pause', 'Pause',
                showsUserInterface: false, cancelNotification: false),
            const AndroidNotificationAction('backup_cancel', 'Cancel',
                showsUserInterface: false, cancelNotification: true),
          ],
        ),
        iOS: const DarwinNotificationDetails(
          presentAlert: false,
          presentBadge: false,
          presentSound: false,
        ),
      ),
      payload: 'backup_running',
    );
  }

  Future<void> _updateOngoingNotificationPaused() async {
    await _ensureChannel();
    final title = 'Auto Backup (paused)';
    const content = 'Tap Resume to continue';

    final nativeService = backgroundServiceInstance;
    if (nativeService is AndroidServiceInstance) {
      await nativeService.setForegroundNotificationInfo(
        title: title,
        content: content,
      );
    }

    await _localNotifs.show(
      id: _notifId,
      title: title,
      body: content,
      notificationDetails: NotificationDetails(
        android: AndroidNotificationDetails(
          _channelId,
          'Auto Backup',
          importance: Importance.defaultImportance,
          priority: Priority.defaultPriority,
          icon: '@drawable/ic_launcher',
          ongoing: true,
          autoCancel: false,
          onlyAlertOnce: true,
          showWhen: false,
          enableVibration: false,
          playSound: false,
          actions: <AndroidNotificationAction>[
            const AndroidNotificationAction('backup_resume', 'Resume',
                showsUserInterface: false, cancelNotification: false),
            const AndroidNotificationAction('backup_cancel', 'Cancel',
                showsUserInterface: false, cancelNotification: true),
          ],
        ),
      ),
      payload: 'backup_paused',
    );
  }

  Future<void> _updateOngoingNotificationResumed() async {
    await _ensureChannel();
    final title = 'Auto Backup';
    const content = 'Resuming…';

    final nativeService = backgroundServiceInstance;
    if (nativeService is AndroidServiceInstance) {
      await nativeService.setForegroundNotificationInfo(
        title: title,
        content: content,
      );
    }

    await _localNotifs.show(
      id: _notifId,
      title: title,
      body: content,
      notificationDetails: NotificationDetails(
        android: AndroidNotificationDetails(
          _channelId,
          'Auto Backup',
          importance: Importance.defaultImportance,
          priority: Priority.defaultPriority,
          icon: '@drawable/ic_launcher',
          ongoing: true,
          autoCancel: false,
          onlyAlertOnce: true,
          showWhen: false,
          enableVibration: false,
          playSound: false,
          actions: <AndroidNotificationAction>[
            const AndroidNotificationAction('backup_pause', 'Pause',
                showsUserInterface: false, cancelNotification: false),
            const AndroidNotificationAction('backup_cancel', 'Cancel',
                showsUserInterface: false, cancelNotification: true),
          ],
        ),
      ),
      payload: 'backup_resumed',
    );
  }

  Future<void> _finishOngoingNotification({
    required bool done,
    required int total,
    required int uploaded,
    int failed = 0,
    String? error,
  }) async {
    await _localNotifs.cancel(id: _notifId);
    String body;
    String title;
    if (!done) {
      title = 'Backup dihentikan';
      body = error ?? 'Dibatalkan user.';
    } else if (failed > 0) {
      title = 'Backup selesai (sebagian)';
      body = '$uploaded dari $total file terupload. $failed gagal — buka progres untuk detail.';
    } else {
      title = 'Backup selesai';
      body = '$uploaded dari $total file terupload.';
    }
    await _localNotifs.show(
      id: _notifId,
      title: title,
      body: body,
      notificationDetails: const NotificationDetails(
        android: AndroidNotificationDetails(
          'enstorage_default',
          'EnStorage Notifications',
          importance: Importance.defaultImportance,
          priority: Priority.defaultPriority,
          icon: '@drawable/ic_launcher',
          autoCancel: true,
        ),
      ),
      payload: 'backup_done',
    );
  }

  Future<void> _cancelOngoingNotification() async {
    await _localNotifs.cancel(id: _notifId);
  }

  Future<void> _showOfflineNotification() async {
    await _localNotifs.show(
      id: _notifId,
      title: 'Backup dilewati',
      body: 'Pengaturan jaringan tidak cocok dengan koneksi saat ini.',
      notificationDetails: const NotificationDetails(
        android: AndroidNotificationDetails(
          'enstorage_default',
          'EnStorage Notifications',
          importance: Importance.defaultImportance,
          priority: Priority.defaultPriority,
          icon: '@drawable/ic_launcher',
          autoCancel: true,
        ),
      ),
      payload: 'backup_offline',
    );
  }

  Future<void> _showPermissionDeniedNotification() async {
    await _localNotifs.show(
      id: _notifId,
      title: 'Backup tidak bisa jalan',
      body: 'Izin akses foto belum diberikan. Buka Settings untuk memberikan.',
      notificationDetails: const NotificationDetails(
        android: AndroidNotificationDetails(
          'enstorage_default',
          'EnStorage Notifications',
          importance: Importance.high,
          priority: Priority.high,
          icon: '@drawable/ic_launcher',
          autoCancel: true,
        ),
      ),
      payload: 'backup_no_permission',
    );
  }

  Future<void> _showAuthMissingNotification() async {
    await _localNotifs.show(
      id: _notifId,
      title: 'Backup dijeda',
      body: 'Silakan login kembali untuk melanjutkan backup.',
      notificationDetails: const NotificationDetails(
        android: AndroidNotificationDetails(
          'enstorage_default',
          'EnStorage Notifications',
          importance: Importance.high,
          priority: Priority.high,
          icon: '@drawable/ic_launcher',
          autoCancel: true,
        ),
      ),
      payload: 'backup_no_auth',
    );
  }

  bool _matchesMode(List<ConnectivityResult> results, BackupNetworkMode mode) {
    final hasWifi = results.contains(ConnectivityResult.wifi);
    final hasMobile = results.contains(ConnectivityResult.mobile);
    switch (mode) {
      case BackupNetworkMode.wifiOnly:
        return hasWifi;
      case BackupNetworkMode.wifiAndMobile:
        return hasWifi || hasMobile;
      case BackupNetworkMode.off:
        return false;
    }
  }

  Future<List<BackupQueueItem>> _scan() async {
    final paths = await PhotoManager.getAssetPathList(
      type: RequestType.common,
      onlyAll: false,
    );
    final List<BackupQueueItem> items = [];
    final seenIds = <String>{};
    for (final path in paths) {
      if (_cancelled) break;
      final count = await path.assetCountAsync;
      if (count == 0) continue;
      final page = await path.getAssetListPaged(page: 0, size: count);
      for (final asset in page) {
        if (_cancelled) break;
        if (seenIds.contains(asset.id)) continue;
        seenIds.add(asset.id);

        final file = await asset.file;
        if (file == null) continue;
        final stat = await file.stat();
        items.add(BackupQueueItem(
          localAssetId: asset.id,
          absolutePath: file.path,
          displayName: file.uri.pathSegments.isNotEmpty
              ? file.uri.pathSegments.last
              : asset.title ?? 'photo_${asset.id}',
          sizeBytes: stat.size,
          modifiedAtMs: stat.modified.millisecondsSinceEpoch,
          status: BackupItemStatus.pending,
        ));
      }
    }
    return items;
  }

  List<String> _devicePathSegments(BackupQueueItem item) {
    final raw = item.absolutePath;
    final parts = raw.split('/').where((s) => s.isNotEmpty).toList();
    if (parts.isEmpty) return [item.displayName];
    const roots = {
      'DCIM',
      'Pictures',
      'Download',
      'Downloads',
      'Movies',
      'Music',
      'WhatsApp',
    };
    var idx = -1;
    for (var i = parts.length - 2; i >= 0; i--) {
      if (roots.contains(parts[i])) {
        idx = i;
        break;
      }
    }
    if (idx >= 0) {
      return parts.sublist(idx);
    }
    return parts.length >= 2 ? parts.sublist(parts.length - 2) : parts;
  }

  Future<String> _hashFile(BackupQueueItem item) async {
    final file = File(item.absolutePath);
    if (!file.existsSync()) {
      throw 'File tidak ditemukan: ${item.absolutePath}';
    }
    final controller = StreamController<Digest>();
    final hashSink = sha256.startChunkedConversion(controller.sink);
    final digestFuture = controller.stream.first;
    await for (final chunk in file.openRead()) {
      hashSink.add(chunk);
    }
    hashSink.close();
    final digest = await digestFuture;
    await controller.close();
    return digest.toString();
  }

  String _stableClientKey(BackupQueueItem item) {
    final composite = '${item.absolutePath}|${item.modifiedAtMs}|${item.sizeBytes}';
    final digest = sha256.convert(utf8.encode(composite));
    return digest.toString().substring(0, 32);
  }
}

final backupServiceProvider = Provider<BackupService>((ref) {
  return BackupService(
    settingsRepo: ref.watch(backupSettingsRepositoryProvider),
    backupRepo: ref.watch(backupRepositoryProvider),
    queueRepo: ref.watch(backupQueueRepositoryProvider),
    tokenStorage: ref.watch(tokenStorageProvider),
  );
});
