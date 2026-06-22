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

  Future<User> googleAuth({required String code}) async {
    try {
      final res = await _api.dio.post<Map<String, dynamic>>(
        '/auth/google',
        data: {'code': code},
      );
      return _persist(res.data!);
    } on DioException catch (e) {
      throw AuthException(_extractMessage(e));
    }
  }

  /// Kept for backward compatibility (existing users who registered
  /// with email/password).
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

  /// Update the current user's name + email. Backend returns the full
  /// updated user payload inside `data.user`; we parse and return it so
  /// the caller can refresh cached state.
  Future<User> updateMe({required String name, required String email}) async {
    try {
      final res = await _api.dio.patch<Map<String, dynamic>>(
        '/auth/me',
        data: {'name': name, 'email': email},
      );
      final body = res.data!;
      final data = (body['data'] as Map<String, dynamic>?) ?? body;
      final userJson = (data['user'] ?? data) as Map<String, dynamic>;
      final user = User.fromJson(userJson);
      // Persist so a cold start reads the new values straight from
      // secure storage without waiting on /me.
      await _tokens.writeUser(user);
      return user;
    } on DioException catch (e) {
      throw AuthException(_extractMessage(e));
    }
  }

  /// Rotate the current user's password. The backend's `confirmed`
  /// rule requires the new password to be passed alongside
  /// `new_password_confirmation` (any value matching `new_password`).
  Future<void> changePassword({
    required String current,
    required String next,
  }) async {
    try {
      await _api.dio.post<Map<String, dynamic>>(
        '/auth/change-password',
        data: {
          'current_password': current,
          'new_password': next,
          'new_password_confirmation': next,
        },
      );
    } on DioException catch (e) {
      throw AuthException(_extractMessage(e));
    }
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
