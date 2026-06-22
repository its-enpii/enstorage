import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Non-secret prefs: locale, theme, last-visited folder, etc.
class AppPrefs {
  AppPrefs(this._prefs);

  static const _localeKey = 'enstorage_locale';
  static const _themeModeKey = 'enstorage_theme_mode';

  final SharedPreferences _prefs;

  static Future<AppPrefs> create() async {
    final prefs = await SharedPreferences.getInstance();
    return AppPrefs(prefs);
  }

  String? get locale => _prefs.getString(_localeKey);
  Future<void> setLocale(String? value) async {
    if (value == null) {
      await _prefs.remove(_localeKey);
    } else {
      await _prefs.setString(_localeKey, value);
    }
  }

  /// ThemeMode is stored as a stable string so pref additions in the
  /// future stay backwards-compatible.
  ThemeMode get themeMode {
    final raw = _prefs.getString(_themeModeKey);
    switch (raw) {
      case 'light':
        return ThemeMode.light;
      case 'dark':
        return ThemeMode.dark;
      case 'system':
      default:
        return ThemeMode.system;
    }
  }

  Future<void> setThemeMode(ThemeMode mode) async {
    final value = switch (mode) {
      ThemeMode.light => 'light',
      ThemeMode.dark => 'dark',
      ThemeMode.system => 'system',
    };
    await _prefs.setString(_themeModeKey, value);
  }
}
