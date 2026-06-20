import 'package:shared_preferences/shared_preferences.dart';

/// Non-secret prefs: locale, theme, last-visited folder, etc.
class AppPrefs {
  AppPrefs(this._prefs);

  static const _localeKey = 'enstorage_locale';

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
}
