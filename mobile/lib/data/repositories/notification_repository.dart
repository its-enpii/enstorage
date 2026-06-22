import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client.dart';

class NotificationSettings {
  const NotificationSettings({
    this.upload = true,
    this.quota = true,
    this.security = true,
  });

  final bool upload;
  final bool quota;
  final bool security;

  NotificationSettings copyWith({bool? upload, bool? quota, bool? security}) {
    return NotificationSettings(
      upload: upload ?? this.upload,
      quota: quota ?? this.quota,
      security: security ?? this.security,
    );
  }

  factory NotificationSettings.fromJson(Map<String, dynamic> json) {
    return NotificationSettings(
      upload: json['notification_upload'] as bool? ?? true,
      quota: json['notification_quota'] as bool? ?? true,
      security: json['notification_security'] as bool? ?? true,
    );
  }
}

class NotificationRepository {
  NotificationRepository(this._api);
  final ApiClient _api;

  /// Register FCM token with the backend.
  Future<void> registerToken(String fcmToken) async {
    await _api.dio.post<Map<String, dynamic>>(
      '/notifications/token',
      data: {'fcm_token': fcmToken, 'platform': 'android'},
    );
  }

  /// Remove FCM token from the backend.
  Future<void> removeToken(String fcmToken) async {
    await _api.dio.delete<Map<String, dynamic>>(
      '/notifications/token',
      data: {'fcm_token': fcmToken},
    );
  }

  /// Get notification settings for the current device.
  Future<NotificationSettings> getSettings() async {
    final res = await _api.dio.get<Map<String, dynamic>>(
      '/notifications/settings',
    );
    final data = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
    return NotificationSettings.fromJson(data);
  }

  /// Update notification settings.
  Future<NotificationSettings> updateSettings({
    bool? upload,
    bool? quota,
    bool? security,
  }) async {
    final body = <String, dynamic>{};
    if (upload != null) body['notification_upload'] = upload;
    if (quota != null) body['notification_quota'] = quota;
    if (security != null) body['notification_security'] = security;

    final res = await _api.dio.patch<Map<String, dynamic>>(
      '/notifications/settings',
      data: body,
    );
    final data = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
    return NotificationSettings.fromJson(data);
  }
}

final notificationRepositoryProvider = Provider<NotificationRepository>((ref) {
  return NotificationRepository(ref.watch(apiClientProvider));
});
