import 'dart:async';
import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../models/user.dart';

/// Secure persistence for the auth token + cached user. Keychain (iOS) /
/// EncryptedSharedPrefs (Android). Reading the cached user is what lets
/// the app open straight to Home on cold start without waiting on /me.
class TokenStorage {
  TokenStorage([FlutterSecureStorage? storage])
      : _storage = storage ?? const FlutterSecureStorage();

  static const _tokenKey = 'enstorage_token';
  static const _userIdKey = 'enstorage_user_id';
  static const _userKey = 'enstorage_user';
  final FlutterSecureStorage _storage;

  /// Reads the persisted token. Side effect: also primes the in-memory
  /// mirror so subsequent `readTokenSync()` calls (used by widgets that
  /// build image URLs) don't race with secure storage on first frame.
  Future<String?> readToken() async {
    final t = await _storage.read(key: _tokenKey);
    if (t != null) _cachedToken = t;
    return t;
  }
  Future<String?> readUserId() => _storage.read(key: _userIdKey);

  /// Synchronous read of the cached token. Returns null if no token
  /// has been written. Used by widgets that build image URLs (the
  /// backend accepts `?token=` for <img> tags that can't set headers).
  /// If the in-memory mirror isn't populated yet, kicks off a one-shot
  /// hydrate from secure storage.
  String? readTokenSync() {
    if (_cachedToken != null) return _cachedToken;
    // Best-effort hydrate. The first call may race with secure storage;
    // subsequent calls (after login) will hit the mirror directly.
    unawaited(_hydrate());
    return null;
  }

  Future<void> _hydrate() async {
    final t = await _storage.read(key: _tokenKey);
    if (t != null) _cachedToken = t;
  }

  /// Read the cached user. Returns null if no token or no cached user.
  Future<User?> readUser() async {
    final token = await readToken();
    if (token == null) return null;
    final raw = await _storage.read(key: _userKey);
    if (raw == null || raw.isEmpty) return null;
    try {
      return User.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null;
    }
  }

  Future<void> save({
    required String token,
    required String userId,
    required User user,
  }) async {
    _cachedToken = token;
    await _storage.write(key: _tokenKey, value: token);
    await _storage.write(key: _userIdKey, value: userId);
    await _storage.write(key: _userKey, value: jsonEncode(user.toJson()));
  }

  /// Update only the cached user (e.g. after a profile update or after
  /// a silent /me refresh that returned a fresher payload).
  Future<void> writeUser(User user) async {
    await _storage.write(key: _userKey, value: jsonEncode(user.toJson()));
  }

  Future<void> clear() async {
    _cachedToken = null;
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _userIdKey);
    await _storage.delete(key: _userKey);
  }

  // In-memory mirror of the last-written token. The `flutter_secure_storage`
  // plugin is async-only, but we need a synchronous read so widgets can
  // build image URLs (for the backend's `?token=` query-param fallback)
  // without an `await` in the build method.
  String? _cachedToken;
}

/// Single shared instance — read by all repositories.
final tokenStorageProvider = Provider<TokenStorage>((ref) => TokenStorage());
