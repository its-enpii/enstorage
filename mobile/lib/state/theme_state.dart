import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/storage/prefs.dart';

/// Persists the user's chosen ThemeMode. `ThemeMode.system` (the default)
/// follows the OS setting; `dark` / `light` are pinned overrides.
class ThemeModeController extends StateNotifier<ThemeMode> {
  ThemeModeController(this._prefs) : super(_prefs.themeMode);

  final AppPrefs _prefs;

  Future<void> setMode(ThemeMode mode) async {
    state = mode;
    await _prefs.setThemeMode(mode);
  }
}

/// Provider is initialized async via main() before runApp.
final themeModeControllerProvider =
    StateNotifierProvider<ThemeModeController, ThemeMode>((ref) {
  throw UnimplementedError('Override in ProviderScope');
});