import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client.dart';
import '../models/user.dart';
import '../storage/token_storage.dart';

class AuthException implements Exception {
  AuthException(this.message);
  final String message;
  @override
  String toString() => message;
}

class AuthRepository {
  AuthRepository(this._api, this._tokens);

  final ApiClient _api;
  final TokenStorage _tokens;

  Future<User> login({required String email, required String password}) async {
    try {
      final res = await _api.dio.post<Map<String, dynamic>>(
        '/auth/login',
        data: {'email': email, 'password': password},
      );
      return _persist(res.data!);
    } on DioException catch (e) {
      throw AuthException(_extractMessage(e));
    }
  }

  Future<User> register({
    required String name,
    required String email,
    required String password,
  }) async {
    try {
      final res = await _api.dio.post<Map<String, dynamic>>(
        '/auth/register',
        data: {'name': name, 'email': email, 'password': password},
      );
      return _persist(res.data!);
    } on DioException catch (e) {
      throw AuthException(_extractMessage(e));
    }
  }

  Future<User?> me() async {
    try {
      final res = await _api.dio.get<Map<String, dynamic>>('/auth/me');
      final body = res.data!;
      // Backend wraps in { success, data: { user }, ... }.
      final data = (body['data'] as Map<String, dynamic>?) ?? body;
      return User.fromJson((data['user'] as Map<String, dynamic>?) ?? data);
    } on DioException {
      return null;
    }
  }

  Future<void> logout() async {
    await _tokens.clear();
  }

  User _persist(Map<String, dynamic> body) {
    // Backend wraps everything in { success, data: { user, token }, message, meta }.
    final data = (body['data'] as Map<String, dynamic>?) ?? body;
    final token = (data['token'] ?? '') as String;
    final userJson = (data['user'] ?? data) as Map<String, dynamic>;
    final user = User.fromJson(userJson);
    // Fire-and-forget. Token write is best-effort during the auth call.
    _tokens.save(token: token, userId: user.id, user: user);
    return user;
  }

  String _extractMessage(DioException e) {
    final data = e.response?.data;
    if (data is Map && data['message'] is String) {
      return data['message'] as String;
    }
    return e.message ?? 'Network error';
  }
}

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  return AuthRepository(
    ref.watch(apiClientProvider),
    ref.watch(tokenStorageProvider),
  );
});
