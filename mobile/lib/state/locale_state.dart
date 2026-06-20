import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/api_client.dart';
import '../data/storage/prefs.dart';

/// App-supported locales. Order matters: first = default.
const supportedLocales = <Locale>[
  Locale('id'),
  Locale('en'),
];

class LocaleController extends StateNotifier<Locale?> {
  LocaleController(this._prefs, this._api) : super(null) {
    final saved = _prefs.locale;
    if (saved != null) {
      state = _parse(saved);
    }
  }

  final AppPrefs _prefs;
  final ApiClient _api;

  Future<void> setLocale(Locale? locale) async {
    state = locale;
    await _prefs.setLocale(locale?.languageCode);
    _api.setLocale(locale?.languageCode);
  }

  Locale _parse(String code) {
    return supportedLocales.firstWhere(
      (l) => l.languageCode == code,
      orElse: () => supportedLocales.first,
    );
  }
}

/// Provider is initialized async via main() before runApp.
final localeControllerProvider =
    StateNotifierProvider<LocaleController, Locale?>((ref) {
  throw UnimplementedError('Override in ProviderScope');
});
