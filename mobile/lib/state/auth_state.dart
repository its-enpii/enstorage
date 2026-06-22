import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_cache_manager/flutter_cache_manager.dart';

import '../data/models/user.dart';
import '../data/repositories/auth_repository.dart';
import '../data/storage/token_storage.dart';
import '../services/notification_service.dart';

class AuthState {
  const AuthState({
    this.user,
    this.loading = false,
    this.error,
  });

  final User? user;
  final bool loading;
  final String? error;

  bool get isAuthenticated => user != null;

  AuthState copyWith({
    User? user,
    bool? loading,
    String? error,
    bool clearUser = false,
    bool clearError = false,
  }) {
    return AuthState(
      user: clearUser ? null : (user ?? this.user),
      loading: loading ?? this.loading,
      error: clearError ? null : (error ?? this.error),
    );
  }
}

class AuthController extends StateNotifier<AuthState> {
  AuthController(
    this._repo,
    this._tokens,
    this._ref, {
    User? initialUser,
  }) : super(AuthState(user: initialUser)) {
    // Skip the /me round-trip when the caller already supplied a cached
    // user (main() reads it from secure storage before runApp). We still
    // fire a silent /me in the background to pick up server-side changes
    // (e.g. profile updates, account deactivation).
    if (initialUser != null) {
      _refreshInBackground();
    } else {
      _hydrate();
    }
  }

  final AuthRepository _repo;
  final TokenStorage _tokens;
  final Ref _ref;

  /// Cold start: read token + cached user, set state synchronously,
  /// then validate against /me. If /me fails (token revoked, account
  /// disabled), clear storage and drop back to the login screen.
  Future<void> _hydrate() async {
    final token = await _tokens.readToken();
    if (token == null) return;
    final cached = await _tokens.readUser();
    if (cached != null) {
      state = state.copyWith(user: cached);
    } else {
      state = state.copyWith(loading: true);
    }
    await _validateAgainstServer();
  }

  /// When called from a state that already has a user, only refresh —
  /// never blank the user out, because that would briefly drop the
  /// router back to /login on a flaky network.
  Future<void> _refreshInBackground() async {
    final user = await _repo.me();
    if (user == null) {
      // Token no longer valid. Clear and log out.
      await _tokens.clear();
      state = const AuthState();
      return;
    }
    state = state.copyWith(user: user);
    await _tokens.writeUser(user);
    registerDeviceToken(_ref);
  }

  Future<void> _validateAgainstServer() async {
    final user = await _repo.me();
    if (user == null) {
      await _tokens.clear();
      state = const AuthState();
      return;
    }
    state = state.copyWith(user: user, loading: false);
    await _tokens.writeUser(user);
  }

  Future<bool> googleLogin(String code) async {
    debugPrint('[AuthController.googleLogin] start');
    state = state.copyWith(loading: true, clearError: true);
    try {
      final user = await _repo.googleAuth(code: code);
      debugPrint('[AuthController.googleLogin] success user=${user.email}');
      state = state.copyWith(user: user, loading: false);
      // Register FCM token with backend after login.
      registerDeviceToken(_ref);
      return true;
    } on AuthException catch (e) {
      debugPrint('[AuthController.googleLogin] auth error: ${e.message}');
      state = state.copyWith(loading: false, error: e.message);
      return false;
    } catch (e, st) {
      debugPrint('[AuthController.googleLogin] UNEXPECTED: $e\n$st');
      state = state.copyWith(loading: false, error: e.toString());
      return false;
    }
  }

  /// Kept for backward compatibility (email/password login).
  Future<bool> login(String email, String password) async {
    debugPrint('[AuthController.login] start');
    state = state.copyWith(loading: true, clearError: true);
    try {
      final user = await _repo.login(email: email, password: password);
      debugPrint('[AuthController.login] success user=${user.email}');
      state = state.copyWith(user: user, loading: false);
      registerDeviceToken(_ref);
      return true;
    } on AuthException catch (e) {
      debugPrint('[AuthController.login] auth error: ${e.message}');
      state = state.copyWith(loading: false, error: e.message);
      return false;
    } catch (e, st) {
      debugPrint('[AuthController.login] UNEXPECTED: $e\n$st');
      state = state.copyWith(loading: false, error: e.toString());
      return false;
    }
  }

  Future<void> logout() async {
    clearDeviceToken(_ref);
    await _repo.logout();
    // Wipe the on-disk image cache so the next signed-in user on this
    // device can't see thumbnails/full previews of files they don't
    // own. Best-effort: failures are non-fatal since logout must not
    // be blocked by cache I/O.
    try {
      await DefaultCacheManager().emptyCache();
    } catch (_) {
      // ignore — cache will be evicted eventually by the OS.
    }
    state = const AuthState();
  }

  /// Update the user's name + email. Returns true on success, false if
  /// the backend rejected the change (error message is on `state.error`).
  Future<bool> updateMe({required String name, required String email}) async {
    state = state.copyWith(loading: true, clearError: true);
    try {
      final user = await _repo.updateMe(name: name, email: email);
      state = state.copyWith(user: user, loading: false);
      return true;
    } on AuthException catch (e) {
      state = state.copyWith(loading: false, error: e.message);
      return false;
    } catch (e, st) {
      debugPrint('[AuthController.updateMe] UNEXPECTED: $e\n$st');
      state = state.copyWith(loading: false, error: e.toString());
      return false;
    }
  }

  /// Rotate the password. Returns true on success. On failure, the
  /// error message is on `state.error` (e.g. "Current password is
  /// incorrect." when the backend rejects the current password).
  Future<bool> changePassword({
    required String current,
    required String next,
  }) async {
    state = state.copyWith(loading: true, clearError: true);
    try {
      await _repo.changePassword(current: current, next: next);
      state = state.copyWith(loading: false);
      return true;
    } on AuthException catch (e) {
      state = state.copyWith(loading: false, error: e.message);
      return false;
    } catch (e, st) {
      debugPrint('[AuthController.changePassword] UNEXPECTED: $e\n$st');
      state = state.copyWith(loading: false, error: e.toString());
      return false;
    }
  }
}

final authControllerProvider =
    StateNotifierProvider<AuthController, AuthState>((ref) {
  return AuthController(
    ref.watch(authRepositoryProvider),
    ref.watch(tokenStorageProvider),
    ref,
  );
});
