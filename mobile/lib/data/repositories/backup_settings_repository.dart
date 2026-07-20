import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../models/backup_settings.dart';
import '../storage/prefs.dart';

/// Persistence untuk [BackupSettings]. Single JSON blob di satu key
/// supaya atomic write (semua field update bersama, gak ada state
/// setengah-setengah kalau crash di tengah).
///
/// Pola sama dengan `mobile/lib/data/storage/prefs.dart` (AppPrefs) —
/// inject dari `main.dart` lewat ProviderScope override.
class BackupSettingsRepository {
  BackupSettingsRepository(this._prefs);

  static const _key = 'enstorage_backup_settings_v2';
  static const _legacyKey = 'enstorage_backup_settings_v1';

  final SharedPreferences _prefs;

  BackupSettings read() {
    final raw = _prefs.getString(_key);
    if (raw == null || raw.isEmpty) {
      // Coba migrate dari v1 jika ada
      final legacy = _prefs.getString(_legacyKey);
      if (legacy != null && legacy.isNotEmpty) {
        try {
          final decoded = jsonDecode(legacy) as Map<String, Object?>;
          final settings = BackupSettings.fromJson(decoded);
          // Simpan ke v2 agar migrate permanent
          write(settings);
          return settings;
        } catch (_) {}
      }
      return BackupSettings.initial;
    }
    try {
      final decoded = jsonDecode(raw) as Map<String, Object?>;
      return BackupSettings.fromJson(decoded);
    } catch (_) {
      // Corrupted pref — fall back ke initial biar app gak crash.
      return BackupSettings.initial;
    }
  }

  Future<void> write(BackupSettings s) async {
    await _prefs.setString(_key, jsonEncode(s.toJson()));
  }

  Future<void> clear() async {
    await _prefs.remove(_key);
    await _prefs.remove(_legacyKey);
  }
}

final backupSettingsRepositoryProvider =
    Provider<BackupSettingsRepository>((ref) {
  return BackupSettingsRepository(ref.watch(appPrefsProvider).prefs);
});