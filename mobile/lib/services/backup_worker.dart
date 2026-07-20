import 'package:flutter/foundation.dart';
import 'package:flutter_background_service/flutter_background_service.dart';
import 'package:flutter_background_service_android/flutter_background_service_android.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:workmanager/workmanager.dart';

import '../data/api_client.dart';
import '../data/db/backup_database.dart';
import '../data/repositories/backup_queue_repository.dart';
import '../data/repositories/backup_repository.dart';
import '../data/repositories/backup_settings_repository.dart';
import '../data/repositories/files_repository.dart';
import '../data/storage/prefs.dart';
import '../data/storage/token_storage.dart';
import 'backup_service.dart';

/// Worker names registered dengan WorkManager.
const String kBackupPeriodicTaskName = 'enstorage_backup_periodic';
const String kBackupOneOffTaskName = 'enstorage_backup_oneoff';

/// Static reference ke ServiceInstance background isolate.
/// Jika bernilai null, berarti kita sedang berada di main UI isolate.
ServiceInstance? backgroundServiceInstance;

/// Mendaftarkan One-Off Task baru untuk melanjutkan antrian jika waktu habis (chaining).
Future<void> registerOneOffBackupTask() async {
  try {
    await Workmanager().registerOneOffTask(
      kBackupOneOffTaskName,
      kBackupOneOffTaskName,
      existingWorkPolicy: ExistingWorkPolicy.append,
      constraints: Constraints(
        networkType: NetworkType.connected,
        requiresBatteryNotLow: true,
      ),
    );
    debugPrint('[BackupWorker] Chained One-Off task registered');
  } catch (e) {
    debugPrint('[BackupWorker] Failed to register One-Off task: $e');
  }
}

// ─── Foreground Service Callback ────────────────────────────────────────

@pragma('vm:entry-point')
void onStart(ServiceInstance service) async {
  backgroundServiceInstance = service;

  if (service is AndroidServiceInstance) {
    service.on('setAsForeground').listen((event) {
      service.setAsForegroundService();
    });
    service.on('setAsBackground').listen((event) {
      service.setAsBackgroundService();
    });
  }

  service.on('stopService').listen((event) {
    service.stopSelf();
  });

  // 1. Core setup manual di background isolate
  final prefs = await SharedPreferences.getInstance();
  final appPrefs = AppPrefs(prefs);
  final tokenStorage = TokenStorage();

  final db = getBackupDatabase();
  final queueRepo = BackupQueueRepository(db);
  final settingsRepo = BackupSettingsRepository(prefs);
  final settings = settingsRepo.read();

  if (!settings.enabled) {
    service.stopSelf();
    return;
  }

  final token = await tokenStorage.readToken();
  if (token == null) {
    service.stopSelf();
    return;
  }

  final apiClient = ApiClient(tokenStorage: tokenStorage);
  apiClient.dio.options.headers['Authorization'] = 'Bearer $token';

  final filesRepo = FilesRepository(apiClient, tokenStorage);
  final backupRepo = BackupRepository(apiClient, filesRepo);

  final backupService = BackupService(
    settingsRepo: settingsRepo,
    backupRepo: backupRepo,
    queueRepo: queueRepo,
    tokenStorage: tokenStorage,
  );

  // Daftarkan action UI listener
  service.on('action').listen((event) {
    final type = event?['type'];
    if (type == 'pause') {
      backupService.pause();
    } else if (type == 'resume') {
      backupService.resume();
    } else if (type == 'cancel') {
      backupService.cancel();
    } else if (type == 'query_status') {
      service.invoke('state', {
        'isRunning': true,
        'paused': backupService.isPaused,
      });
    }
  });

  // Emit status running ke main UI
  service.invoke('state', {'isRunning': true, 'paused': false});

  try {
    // Jalankan pipeline upload sequential di background service isolate
    await backupService.runPipeline(isBackground: false);
  } finally {
    // Selesai, emit idle state dan matikan service
    service.invoke('state', {'isRunning': false, 'paused': false});
    service.stopSelf();
  }
}

/// Inisialisasi Flutter Background Service
Future<void> initializeBackgroundService() async {
  final service = FlutterBackgroundService();
  await service.configure(
    androidConfiguration: AndroidConfiguration(
      onStart: onStart,
      autoStart: false,
      isForegroundMode: true,
      notificationChannelId: 'enstorage_backup_progress',
      initialNotificationTitle: 'Auto Backup',
      initialNotificationContent: 'Memulai backup...',
      foregroundServiceTypes: [AndroidForegroundType.dataSync],
    ),
    iosConfiguration: IosConfiguration(
      autoStart: false,
      onForeground: onStart,
    ),
  );
}

// ─── WorkManager Dispatcher ─────────────────────────────────────────────

@pragma('vm:entry-point')
void backupWorkmanagerCallback() {
  Workmanager().executeTask((task, inputData) async {
    debugPrint('[BackupWorker] WorkManager periodic trigger -> Starting Foreground Service');
    final service = FlutterBackgroundService();
    final isRunning = await service.isRunning();
    if (!isRunning) {
      await service.startService();
    }
    return true;
  });
}

/// Panggil sekali dari main() SEBELUM runApp() untuk install dispatcher.
Future<void> initBackupWorkmanager({bool isInDebugMode = false}) async {
  try {
    await Workmanager().initialize(
      backupWorkmanagerCallback,
      isInDebugMode: isInDebugMode,
    );
  } catch (e) {
    debugPrint('[BackupWorker] WorkManager init failed: $e');
  }
}

/// Schedule periodic backup task. Dipanggil dari settings saat user toggle ON.
Future<void> scheduleBackup(int intervalHours, {required bool requiresCharging}) async {
  try {
    await Workmanager().registerPeriodicTask(
      kBackupPeriodicTaskName,
      kBackupPeriodicTaskName,
      frequency: Duration(hours: intervalHours.clamp(1, 24)),
      existingWorkPolicy: ExistingPeriodicWorkPolicy.replace,
      constraints: Constraints(
        networkType: NetworkType.connected,
        requiresBatteryNotLow: true,
        requiresCharging: requiresCharging,
      ),
    );
    debugPrint('[BackupWorker] Periodic backup scheduled. Frequency: $intervalHours hours');
  } catch (e) {
    debugPrint('[BackupWorker] schedule failed: $e');
  }
}

/// Cancel periodic backup. Dipanggil dari settings saat user toggle OFF.
Future<void> cancelBackup() async {
  try {
    await Workmanager().cancelByUniqueName(kBackupPeriodicTaskName);
    await Workmanager().cancelByUniqueName(kBackupOneOffTaskName);
    final service = FlutterBackgroundService();
    if (await service.isRunning()) {
      service.invoke('stopService');
    }
    debugPrint('[BackupWorker] All background backup tasks cancelled');
  } catch (e) {
    debugPrint('[BackupWorker] cancel failed: $e');
  }
}
