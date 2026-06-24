import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'storage/token_storage.dart';

/// Build the configured Dio. Reads base URL from `--dart-define=API_BASE=...`.
///
/// `API_BASE` MUST be supplied at build time via `scripts/run_dev.sh` (which
/// sources `.env.local`). The Dart const has no `defaultValue` on purpose:
/// if the dev forgets to set it, the assertion below fires at first access
/// instead of silently pointing the app at a guessed URL.
///
/// Examples:
///   Android emulator → host PC : http://10.0.2.2:8080/api/v1
///   iOS simulator               : http://localhost:8080/api/v1
///   Physical device on LAN      : http://<your-LAN-IP>:8080/api/v1
///   Production                  : https://api.example.com/api/v1
const String kApiBase = String.fromEnvironment('API_BASE');

/// Validates and returns [kApiBase], throwing a clear [StateError] if it
/// was never supplied. Called eagerly so the failure shows up in the same
/// stack frame as `runApp()` rather than as a confusing network error on
/// the first request.
String _resolveApiBase() {
  assert(
    kApiBase.isNotEmpty,
    'API_BASE is empty. Set it in mobile/.env.local and run via '
    '`./scripts/run_dev.sh` so the value is forwarded as --dart-define.',
  );
  if (kApiBase.isEmpty) {
    throw StateError(
      'API_BASE is not configured. Add API_BASE=... to mobile/.env.local '
      'and run the app via ./scripts/run_dev.sh.',
    );
  }
  return kApiBase;
}

class ApiClient {
  ApiClient({required TokenStorage tokenStorage, String? locale})
      : _tokenStorage = tokenStorage,
        _locale = locale,
        _dio = Dio(BaseOptions(
          baseUrl: _resolveApiBase(),
          connectTimeout: const Duration(seconds: 10),
          receiveTimeout: const Duration(seconds: 30),
          sendTimeout: const Duration(minutes: 10),
          headers: {'Accept': 'application/json'},
        )) {
    // Log once so the dev can see which backend the build is pointed at.
    // Strip trailing slashes for readability.
    final shown = kApiBase.endsWith('/')
        ? kApiBase.substring(0, kApiBase.length - 1)
        : kApiBase;
    debugPrint('[ApiClient] baseUrl = $shown');
    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await _tokenStorage.readToken();
        if (token != null) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        if (_locale != null) {
          options.headers['Accept-Language'] = _locale;
        }
        handler.next(options);
      },
    ));
  }

  final Dio _dio;
  final TokenStorage _tokenStorage;
  String? _locale;

  Dio get dio => _dio;
  void setLocale(String? locale) {
    _locale = locale;
  }
}

final apiClientProvider = Provider<ApiClient>((ref) {
  return ApiClient(tokenStorage: ref.watch(tokenStorageProvider));
});
