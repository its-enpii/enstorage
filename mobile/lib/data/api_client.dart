import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'storage/token_storage.dart';

/// Build the configured Dio. Reads base URL from `--dart-define=API_BASE=...`,
/// defaults to `http://10.0.2.2:8080/api/v1` (Android emulator → host on port 8080,
/// which is the Nginx-exposed port of the docker-compose backend).
/// For physical devices, pass:
///   --dart-define=API_BASE=http://<LAN-IP>:8080/api/v1
const String kApiBase = String.fromEnvironment(
  'API_BASE',
  defaultValue: 'http://10.0.2.2:8080/api/v1',
);

class ApiClient {
  ApiClient({required TokenStorage tokenStorage, String? locale})
      : _tokenStorage = tokenStorage,
        _locale = locale,
        _dio = Dio(BaseOptions(
          baseUrl: kApiBase,
          connectTimeout: const Duration(seconds: 10),
          receiveTimeout: const Duration(seconds: 30),
          sendTimeout: const Duration(minutes: 10),
          headers: {'Accept': 'application/json'},
        )) {
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
