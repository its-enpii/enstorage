import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api_client.dart';
import '../models/google_account.dart';

/// Thin wrapper around `/google-accounts` endpoints. Mirrors the
/// pagination-tolerant parsing used by [FilesRepository] — backend
/// wraps everything in `{ success, data, message, meta }`, with
/// `data` being the array for index and the object for everything
/// else.
class GoogleAccountsRepository {
  GoogleAccountsRepository(this._api);
  final ApiClient _api;

  /// `GET /google-accounts`. Returns the full list — the typical user
  /// has only a handful of connected accounts, so we just flatten
  /// paginated responses into a single list.
  Future<List<GoogleAccount>> listAccounts() async {
    final res = await _api.dio.get<Map<String, dynamic>>('/google-accounts');
    final inner = res.data?['data'];
    if (inner is List) {
      return inner
          .whereType<Map<String, dynamic>>()
          .map(GoogleAccount.fromJson)
          .toList(growable: false);
    }
    return const [];
  }

  /// `GET /google-accounts/{id}?with_quota=1`. The `with_quota` flag
  /// forces the resource to fetch a fresh quota from Google, so this
  /// is also useful as a "refresh one account" call.
  Future<GoogleAccount> getAccount(String id) async {
    final res = await _api.dio.get<Map<String, dynamic>>(
      '/google-accounts/$id',
      queryParameters: {'with_quota': '1'},
    );
    final inner = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
    return GoogleAccount.fromJson(inner);
  }

  /// `GET /google-accounts/oauth/redirect`. Returns the Google OAuth
  /// authorization URL the user must open in a browser. Backend
  /// encodes user identity + platform into a signed `state` so the
  /// callback (web) or exchange endpoint (mobile) can resolve which
  /// user to attach the account to and which redirect_uri to use
  /// when swapping the auth code.
  ///
  /// Pass `platform: 'mobile'` to switch the redirect_uri to the
  /// custom URL scheme registered in Info.plist / AndroidManifest.
  Future<String> getOAuthRedirectUrl({String platform = 'web'}) async {
    final res = await _api.dio.get<Map<String, dynamic>>(
      '/google-accounts/oauth/redirect',
      queryParameters: {'platform': platform},
    );
    final inner = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
    final url = inner['authorization_url'] as String?;
    if (url == null || url.isEmpty) {
      throw StateError('authorization_url missing in response');
    }
    return url;
  }

  /// `POST /google-accounts/oauth/exchange`. Mobile-only — called
  /// after the app intercepts the `enstorage://oauth-callback?code=
  /// &state=` deep link. Backend uses the signed `state` to identify
  /// the user and exchange the code with the mobile redirect_uri,
  /// then returns the new GoogleAccount resource.
  Future<GoogleAccount> exchangeOAuthCode({
    required String code,
    required String state,
  }) async {
    final res = await _api.dio.post<Map<String, dynamic>>(
      '/google-accounts/oauth/exchange',
      data: {'code': code, 'state': state},
    );
    final inner = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
    return GoogleAccount.fromJson(inner);
  }

  /// `POST /google-accounts/{id}/sync-quota`. Returns the freshly
  /// synced quota.
  Future<GoogleAccountQuota> syncQuota(String id) async {
    final res = await _api.dio.post<Map<String, dynamic>>(
      '/google-accounts/$id/sync-quota',
    );
    final inner = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
    final quota = inner['quota'];
    if (quota is Map<String, dynamic>) {
      return GoogleAccountQuota.fromJson(quota);
    }
    throw StateError('quota missing in sync response');
  }

  /// `PATCH /google-accounts/{id}` with `{ label }`. The label is a
  /// user-facing alias to distinguish multiple Google accounts; the
  /// default equals the email.
  Future<GoogleAccount> updateLabel(String id, String label) async {
    final res = await _api.dio.patch<Map<String, dynamic>>(
      '/google-accounts/$id',
      data: {'label': label},
    );
    final inner = (res.data?['data'] as Map<String, dynamic>?) ?? const {};
    return GoogleAccount.fromJson(inner);
  }

  /// `DELETE /google-accounts/{id}`. Backend revokes the Google
  /// access token (best-effort) and removes the row.
  Future<void> disconnect(String id) async {
    await _api.dio.delete<void>('/google-accounts/$id');
  }
}

final googleAccountsRepositoryProvider =
    Provider<GoogleAccountsRepository>((ref) {
  return GoogleAccountsRepository(ref.watch(apiClientProvider));
});

/// FutureProvider that watches the repository so a `ref.invalidate`
/// after a mutation refreshes the list.
final googleAccountsProvider =
    FutureProvider.autoDispose<List<GoogleAccount>>((ref) async {
  ref.watch(googleAccountsRepositoryProvider);
  final repo = ref.read(googleAccountsRepositoryProvider);
  return repo.listAccounts();
});
