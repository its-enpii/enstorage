import 'package:dio/dio.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/models/file_item.dart';
import '../data/repositories/notification_repository.dart';
import '../state/refresh_signal_state.dart';

/// Global scaffold messenger key — lets background services show
/// snackbars from anywhere in the app.
final GlobalKey<ScaffoldMessengerState> rootScaffoldMessengerKey =
    GlobalKey<ScaffoldMessengerState>();

final FlutterLocalNotificationsPlugin _localNotifs =
    FlutterLocalNotificationsPlugin();

const AndroidNotificationChannel _channel = AndroidNotificationChannel(
  'enstorage_default',
  'EnStorage Notifications',
  description: 'Upload, quota, and security notifications',
  importance: Importance.high,
);

/// Channel khusus untuk upload progress: ongoing, no badge.
/// Importance default supaya Infinix/Transsion ROM gak sembunyiin.
/// Sound/vibration di-disable per-notifikasi via onlyAlertOnce.
const AndroidNotificationChannel _uploadChannel = AndroidNotificationChannel(
  'enstorage_upload_progress',
  'Upload Progress',
  description: 'Ongoing upload progress in system tray',
  importance: Importance.defaultImportance,
  playSound: false,
  enableVibration: false,
  showBadge: false,
);

/// ID tetap untuk notif upload aktif. Karena cuma 1 upload concurrent
/// yang penting buat user, kita pakai 1 notif aja. Multi-upload bisa
/// di-extend ke multiple ID nanti.
const int _uploadNotifBaseId = 9000;

Future<void> _initLocalNotifications() async {
  const androidInit = AndroidInitializationSettings('@drawable/ic_launcher');
  const initSettings = InitializationSettings(android: androidInit);
  await _localNotifs.initialize(settings: initSettings);

  // Create channels (Android 8+).
  final androidImpl = _localNotifs.resolvePlatformSpecificImplementation<
      AndroidFlutterLocalNotificationsPlugin>();
  await androidImpl?.createNotificationChannel(_channel);
  await androidImpl?.createNotificationChannel(_uploadChannel);

  // Request permission (Android 13+).
  await androidImpl?.requestNotificationsPermission();
}

void _showLocalNotification(RemoteMessage message) {
  final notif = message.notification;
  if (notif == null) return;
  _localNotifs.show(
    id: notif.hashCode,
    title: notif.title,
    body: notif.body,
    notificationDetails: NotificationDetails(
      android: AndroidNotificationDetails(
        _channel.id,
        _channel.name,
        channelDescription: _channel.description,
        importance: Importance.high,
        priority: Priority.high,
        icon: '@drawable/ic_launcher',
      ),
    ),
    payload: message.data.toString(),
  );
}

/// Top-level handler for background messages (app terminated / background).
/// Must be a top-level function (not a method).
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();
}

/// Initializes Firebase + FCM, requests permission, and sets up
/// message handlers. Call from `main()` before `runApp()`.
///
/// [onTokenReady] is called as soon as we have an FCM token — both
/// for the initial token AND for any rotation. Pass a callback that
/// calls `registerDeviceToken(ref)` (or its token-string variant) with
/// the active ProviderContainer.
Future<void> initNotifications({
  Future<void> Function(String token)? onTokenReady,
}) async {
  await Firebase.initializeApp();

  // Local notifications setup.
  await _initLocalNotifications();

  final messaging = FirebaseMessaging.instance;

  // Get initial token — may take a beat after Firebase.initializeApp.
  // Retry a few times since FCM isn't always ready immediately.
  String? initialToken;
  for (var attempt = 1; attempt <= 5; attempt++) {
    initialToken = await messaging.getToken();
    if (initialToken != null) break;
    await Future<void>.delayed(Duration(seconds: attempt * 2));
  }
  if (initialToken != null) {
    if (onTokenReady != null) {
      // ignore: discarded_futures
      onTokenReady(initialToken);
    }
  }

  // Request permission (iOS + Android 13+).
  await messaging.requestPermission(
    alert: true,
    badge: true,
    sound: true,
  );

  // Background handler — must be registered before runApp().
  FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);

  // Foreground handler — route to upload handler or generic.
  FirebaseMessaging.onMessage.listen((RemoteMessage message) {
    final type = message.data['type'];
    if (type is String &&
        (type == 'upload.progress' ||
            type == 'upload.complete' ||
            type == 'upload.failed')) {
      _handleUploadMessage(message, type);
    } else {
      _showLocalNotification(message);
    }
  });

  // When user taps a notification that opened the app from terminated.
  FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage message) {
    // TODO: navigate based on message.data['type']
  });

  // Token rotation listener — re-register when FCM rotates the token.
  messaging.onTokenRefresh.listen((String newToken) async {
    if (onTokenReady != null) {
      await onTokenReady(newToken);
    }
  });
}

/// Tangani FCM message dengan type upload.* — digunakan cuma untuk
/// event terminal (done/failed) supaya app yg di-kill juga dpt notif.
/// Progress detail di-handle lokal di app via showUploadProgress().
void _handleUploadMessage(RemoteMessage message, String type) {
  final data = message.data;
  final fileName = data['file_name'] as String? ?? '';
  final rawFolder = data['folder_id'] as String?;
  final folderId = (rawFolder == null || rawFolder.isEmpty) ? null : rawFolder;

  if (type == 'upload.complete') {
    finishUpload(
      filename: fileName,
      success: true,
      title: message.notification?.title,
      body: message.notification?.body,
    );
    // Append file baru ke list di folder yang relevan — no API call.
    final fileId = data['file_id'] as String?;
    if (fileId != null) {
      final file = FileItem(
        id: fileId,
        name: fileName,
        mimeType: (data['mime_type'] as String?) ?? 'application/octet-stream',
        size: int.tryParse(data['size'] as String? ?? '0') ?? 0,
        uploadStatus: UploadStatus.done,
        hasThumbnail: (data['has_thumbnail'] as String?) == 'true',
        isStarred: false,
        folderId: folderId,
      );
      notifyAppendFile(folderId, file);
    }
  } else if (type == 'upload.failed') {
    finishUpload(
      filename: fileName,
      success: false,
      title: message.notification?.title,
      body: message.notification?.body,
    );
  }
}

/// Translate exception apapun jadi pesan singkat, ramah untuk user.
/// Default mode: bahasa manusia umum. Kalau [technical] true, sertakan
/// ringkasan teknis (untuk debugging).
String friendlyErrorMessage(Object error, {bool technical = false}) {
  // DioException — translate HTTP status code.
  if (error is DioException) {
    final code = error.response?.statusCode;
    final msg = switch (code) {
      400 => 'Permintaan tidak valid',
      401 => 'Sesi berakhir, silakan login ulang',
      403 => 'Akses ditolak',
      404 => 'Tidak ditemukan di server',
      409 => 'File sudah pernah diupload',
      413 => 'File terlalu besar',
      422 => 'File tidak valid',
      429 => 'Terlalu banyak permintaan, coba lagi nanti',
      500 => 'Server error, coba lagi',
      502 || 503 || 504 => 'Server tidak tersedia, coba lagi',
      _ => null,
    };
    if (msg != null) {
      return technical ? '$msg (HTTP $code)' : msg;
    }
    // No HTTP response — network error.
    final type = error.type.name;
    return switch (type) {
      'connectionTimeout' || 'sendTimeout' || 'receiveTimeout' =>
        technical ? 'Koneksi timeout (${type})' : 'Koneksi timeout',
      'connectionError' =>
        technical ? 'Tidak bisa terhubung ke server' : 'Tidak ada koneksi internet',
      'cancel' => 'Dibatalkan',
      _ => technical ? 'Gagal: ${type}' : 'Upload gagal, coba lagi',
    };
  }
  // Fallback — jangan tampilkan stack trace panjang.
  final raw = error.toString();
  if (technical) return raw.split('\n').first;
  // Generic plain-text fallback.
  if (raw.contains('SocketException') ||
      raw.contains('Connection refused') ||
      raw.contains('Network is unreachable')) {
    return 'Tidak ada koneksi internet';
  }
  if (raw.contains('TimeoutException') || raw.contains('timeout')) {
    return 'Koneksi timeout';
  }
  return 'Upload gagal, coba lagi';
}

/// Tampilkan notif upload progress di system tray (ongoing, update in-place).
/// Panggil saat user mulai upload, lalu tiap ada progress update.
///
/// [progress] 0..100 — pakai [indeterminate] true untuk status "memproses
/// di server" (setelah HTTP done, nunggu backend).
void showUploadProgress({
  required String filename,
  required int progress,
  bool indeterminate = false,
}) {
  final percent = progress.clamp(0, 100);
  _localNotifs.show(
    id: _uploadNotifBaseId,
    title: indeterminate ? 'Memproses di server' : 'Mengupload',
    body: indeterminate
        ? filename
        : '$filename • $percent%',
    notificationDetails: NotificationDetails(
      android: AndroidNotificationDetails(
        _uploadChannel.id,
        _uploadChannel.name,
        channelDescription: _uploadChannel.description,
        importance: Importance.defaultImportance,
        priority: Priority.defaultPriority,
        icon: '@drawable/ic_launcher',
        showProgress: true,
        maxProgress: 100,
        progress: percent,
        indeterminate: indeterminate,
        ongoing: true,
        autoCancel: false,
        onlyAlertOnce: true,
        enableVibration: false,
        playSound: false,
        showWhen: false,
      ),
    ),
    payload: 'upload_progress:$filename',
  );
}

/// Cancel notif progress & tampilkan notifikasi terminal (selesai / gagal).
///
/// [error] Dio/Exception object — akan di-translate ke pesan ramah via
/// [friendlyErrorMessage]. Kalau [error] null, pakai [title]/[body] langsung.
void finishUpload({
  required String filename,
  required bool success,
  String? title,
  String? body,
  Object? error,
  bool technical = false,
}) {
  _localNotifs.cancel(id: _uploadNotifBaseId);
  final String resolvedBody;
  final String resolvedTitle;
  if (success) {
    resolvedTitle = title ?? 'Upload Selesai';
    resolvedBody = body ?? '$filename berhasil diupload.';
  } else {
    resolvedTitle = title ?? 'Upload Gagal';
    if (body != null) {
      resolvedBody = body;
    } else if (error != null) {
      resolvedBody = '$filename: ${friendlyErrorMessage(error, technical: technical)}';
    } else {
      resolvedBody = '$filename gagal diupload.';
    }
  }
  _localNotifs.show(
    id: _uploadNotifBaseId,
    title: resolvedTitle,
    body: resolvedBody,
    notificationDetails: NotificationDetails(
      android: AndroidNotificationDetails(
        _channel.id,
        _channel.name,
        channelDescription: _channel.description,
        importance: success ? Importance.defaultImportance : Importance.high,
        priority: success ? Priority.defaultPriority : Priority.high,
        icon: '@drawable/ic_launcher',
        ongoing: false,
        autoCancel: true,
      ),
    ),
    payload: 'upload_${success ? 'done' : 'failed'}:$filename',
  );
}

/// Registers the current FCM token with the backend.
/// Call after login succeeds.
Future<void> registerDeviceToken(Ref ref) async {
  try {
    final token = await FirebaseMessaging.instance.getToken();
    if (token == null) {
      return;
    }
    final repo = ref.read(notificationRepositoryProvider);
    await repo.registerToken(token);
  } catch (_) {}
}

/// Register an already-obtained FCM token with the backend.
/// Used by token rotation listener + initial token path.
Future<void> registerDeviceTokenByToken(String token, ProviderContainer container) async {
  try {
    await container.read(notificationRepositoryProvider).registerToken(token);
  } catch (_) {}
}

/// Removes the FCM token from the backend + local.
/// Call on logout.
Future<void> clearDeviceToken(Ref ref) async {
  try {
    final token = await FirebaseMessaging.instance.getToken();
    if (token != null) {
      await ref.read(notificationRepositoryProvider).removeToken(token);
    }
    await FirebaseMessaging.instance.deleteToken();
  } catch (_) {}
}
