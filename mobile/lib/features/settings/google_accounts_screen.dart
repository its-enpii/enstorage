import 'dart:async';

import 'package:flutter/foundation.dart' show debugPrint;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show PlatformException;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:dio/dio.dart';

import '../../data/models/google_account.dart';
import '../../data/repositories/google_accounts_repository.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../state/storage_state.dart';
import '../../theme/radii.dart';
import '../../theme/shadows.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_card.dart';
import '../../widgets/etheric_fab.dart';
import '../../widgets/app_snackbar.dart';
import '../../widgets/list_menu_sheet.dart';
import '../../widgets/nav_aware_sheet.dart';

/// Scopes that EnStorage requests when connecting a Google account.
/// `drive.file` gives read/write access to files the app creates in
/// Drive — sufficient for the vault use case without claiming the
/// full Drive scope.
const List<String> _kScopes = <String>[
  'https://www.googleapis.com/auth/drive.file',
];

/// Web OAuth client ID used as `serverClientId` for the
/// `GoogleSignIn` constructor (v6.x).
///
/// On Android, this is the **Web** OAuth client (type 3) registered
/// in GCP. The native SDK returns `server_auth_code` which the
/// backend exchanges with the **same** Web client's `client_id` +
/// `client_secret` + `redirect_uri=postmessage`. Both must be the
/// same OAuth client in the same GCP project.
///
/// The OAuth `client_secret` is a server-side credential and must
/// NEVER live in this app. It belongs on the backend that performs
/// the code-for-token exchange.
///
/// Injected at build time via:
///   ./scripts/run_dev.sh
/// which reads `GOOGLE_CLIENT_ID` from `.env.local` and passes it as
/// `--dart-define`. In CI, pass it via the same flag.
const String _kWebClientId = String.fromEnvironment('GOOGLE_CLIENT_ID');

/// Connected Google accounts. Responsibilities:
///
/// 1. Show the list of connected accounts (with quota per row).
/// 2. Connect a new account via `google_sign_in` native SDK +
///    `google-services.json`.
/// 3. Per-account actions: sync quota, edit label, disconnect.
///
/// Uses `google_sign_in` v6.x — the v7 SDK on Android uses Android
/// Credential Manager (HiddenActivity), which renders a stuck /
/// non-interactive picker on Infinix / Transsion devices running
/// GMS 26.x. v6 uses an intent-based WebView flow that works on
/// these devices.
class GoogleAccountsScreen extends ConsumerStatefulWidget {
  const GoogleAccountsScreen({super.key});

  @override
  ConsumerState<GoogleAccountsScreen> createState() =>
      _GoogleAccountsScreenState();
}

class _GoogleAccountsScreenState extends ConsumerState<GoogleAccountsScreen> {
  bool _connecting = false;

  /// v6.x API: `GoogleSignIn` is a regular constructor (not a
  /// `.instance` singleton). `serverClientId` is a constructor arg.
  final GoogleSignIn _googleSignIn = GoogleSignIn(
    scopes: _kScopes,
    serverClientId: _kWebClientId,
  );

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final accounts = ref.watch(googleAccountsProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.googleAccountsTitle)),
      body: SafeArea(
        top: false,
        child: Stack(
          children: [
            RefreshIndicator(
              onRefresh: () async {
                ref.invalidate(googleAccountsProvider);
                await ref.read(googleAccountsProvider.future);
              },
              child: accounts.when(
                loading: () => const _LoadingState(),
                error: (e, _) => _ErrorState(
                  message: l10n.commonError,
                  onRetry: () => ref.invalidate(googleAccountsProvider),
                ),
                data: (list) {
                  if (list.isEmpty) {
                    return _EmptyState(l10n: l10n);
                  }
                  return ListView.separated(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.fromLTRB(
                      AppSpacing.containerPadding,
                      12,
                      AppSpacing.containerPadding,
                      120,
                    ),
                    itemCount: list.length,
                    separatorBuilder: (_, __) => const SizedBox(height: 12),
                    itemBuilder: (ctx, i) {
                      final acc = list[i];
                      return _GoogleAccountCard(
                        account: acc,
                        onTap: () => _showActionSheet(acc),
                      );
                    },
                  );
                },
              ),
            ),
            Positioned(
              right: AppSpacing.fabHorizontal,
              bottom: AppSpacing.fabBottom,
              child: _connecting
                  ? const _ConnectingFab()
                  : EthericFab(onTap: _onConnect),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _onConnect() async {
    if (_connecting) return;
    setState(() => _connecting = true);

    final l10n = AppLocalizations.of(context)!;
    final repo = ref.read(googleAccountsRepositoryProvider);

    try {
      // v6 sign-in: triggers the OS account picker (intent-based on
      // Android) and returns a `GoogleSignInAccount` on success.
      // `null` = user dismissed.
      final GoogleSignInAccount? account = await _googleSignIn.signIn();
      if (account == null) {
        debugPrint('[google_accounts] signIn returned null (user cancelled)');
        return;
      }
      debugPrint(
        '[google_accounts] account: id=${account.id} '
        'email=${account.email} displayName=${account.displayName}',
      );

      // v6.x exposes `serverAuthCode` on `GoogleSignInAccount`. The
      // code is only populated when `serverClientId` is set on the
      // `GoogleSignIn` instance and the user has completed the
      // consent screen.
      final String? code = account.serverAuthCode;
      if (code == null || code.isEmpty) {
        await _showError(
          'serverAuthCode null/kosong setelah authentication. '
          'Pastikan serverClientId valid dan cocok dengan client '
          'yang punya client_secret.',
        );
        return;
      }

      await repo.exchangeServerAuthCode(code: code);
      // Sign out from the native session so the OS doesn't keep a
      // cached "last signed-in" account — the source of truth is our
      // backend's `google_accounts` table.
      unawaited(_googleSignIn.signOut());

      ref.invalidate(googleAccountsProvider);
      // Refresh the homepage storage summary so the user sees the
      // newly-connected account's quota right after returning to home.
      ref.invalidate(storageSummaryProvider);
      if (!mounted) return;
      showAppSnackBar(context, l10n.googleAccountsExchangeSuccess,
          variant: AppSnackBarVariant.success);
    } on PlatformException catch (e) {
      // v6.x surfaces cancellations and configuration errors as
      // PlatformException. `code` is one of:
      //   'sign_in_canceled'        — user dismissed the picker
      //   'network_error'           — no connectivity
      //   'sign_in_failed'          — generic failure
      //   'sign_in_required'        — no active session
      debugPrint(
        '[google_accounts] PlatformException: code=${e.code} '
        'message=${e.message} details=${e.details}',
      );
      if (e.code == 'sign_in_canceled') return;
      if (!mounted) return;
      showAppSnackBar(
        context,
        'Sign-in gagal (${e.code}): ${e.message ?? '(no message)'}',
        variant: AppSnackBarVariant.error,
        duration: const Duration(seconds: 6),
      );
    } catch (e, st) {
      debugPrint('[google_accounts] UNEXPECTED: $e\n$st');
      // Surface backend error body (422 from exchange) so we can see
      // the exact OAuth error message without going through laravel.log.
      if (e is DioException && e.response != null) {
        debugPrint(
          '[google_accounts] backend response '
          'status=${e.response?.statusCode} '
          'body=${e.response?.data}',
        );
      }
      if (!mounted) return;
      showAppSnackBar(
        context,
        'Unexpected error: $e',
        variant: AppSnackBarVariant.error,
        duration: const Duration(seconds: 6),
      );
    } finally {
      if (mounted) setState(() => _connecting = false);
    }
  }

  Future<void> _showError(String msg) async {
    debugPrint('[google_accounts] $msg');
    if (!mounted) return;
    final l10n = AppLocalizations.of(context)!;
    showAppSnackBar(
      context,
      l10n.googleAccountsExchangeFailed,
      variant: AppSnackBarVariant.error,
      duration: const Duration(seconds: 6),
    );
  }

  Future<void> _onSync(GoogleAccount account) async {
    final l10n = AppLocalizations.of(context)!;
    final repo = ref.read(googleAccountsRepositoryProvider);
    try {
      await repo.syncQuota(account.id);
      ref.invalidate(googleAccountsProvider);
      ref.invalidate(storageSummaryProvider);
      if (!mounted) return;
      showAppSnackBar(context, l10n.googleAccountsSyncSuccess,
          variant: AppSnackBarVariant.success);
    } catch (_) {
      if (!mounted) return;
      showAppSnackBar(context, l10n.googleAccountsSyncFailed,
          variant: AppSnackBarVariant.error);
    }
  }

  Future<void> _onEditLabel(GoogleAccount account) async {
    final l10n = AppLocalizations.of(context)!;
    final newLabel = await showDialog<String>(
      context: context,
      builder: (_) => _EditLabelDialog(initial: account.label),
    );
    if (newLabel == null || newLabel.isEmpty || newLabel == account.label) {
      return;
    }
    final repo = ref.read(googleAccountsRepositoryProvider);
    try {
      await repo.updateLabel(account.id, newLabel);
      ref.invalidate(googleAccountsProvider);
      if (!mounted) return;
      showAppSnackBar(context, l10n.googleAccountsLabelUpdated,
          variant: AppSnackBarVariant.success);
    } catch (_) {
      if (!mounted) return;
      showAppSnackBar(context, l10n.googleAccountsLabelFailed,
          variant: AppSnackBarVariant.error);
    }
  }

  Future<void> _onDisconnect(GoogleAccount account) async {
    final l10n = AppLocalizations.of(context)!;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: Theme.of(ctx).colorScheme.surfaceContainer,
        title: Text(l10n.googleAccountsDisconnectTitle),
        content: Text(l10n.googleAccountsDisconnectBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: Text(l10n.commonCancel),
          ),
          TextButton(
            style: TextButton.styleFrom(
                foregroundColor: Theme.of(ctx).colorScheme.error),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: Text(l10n.googleAccountsDisconnectConfirm),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    final repo = ref.read(googleAccountsRepositoryProvider);
    try {
      await repo.disconnect(account.id);
      ref.invalidate(googleAccountsProvider);
      ref.invalidate(storageSummaryProvider);
      if (!mounted) return;
      showAppSnackBar(context, l10n.googleAccountsDisconnected,
          variant: AppSnackBarVariant.success);
    } catch (_) {
      if (!mounted) return;
      showAppSnackBar(context, l10n.googleAccountsDisconnectFailed,
          variant: AppSnackBarVariant.error);
    }
  }

  void _showActionSheet(GoogleAccount account) {
    final l10n = AppLocalizations.of(context)!;
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: Theme.of(context).colorScheme.surface,
      shape: const RoundedRectangleBorder(borderRadius: AppRadii.topSheetBorder),
      isScrollControlled: true,
      useRootNavigator: true,
      builder: (ctx) {
        final scheme = Theme.of(ctx).colorScheme;
        return NavAwareSheet(
          child: ListMenuSheet(
            title: account.email,
            children: [
              ListMenuTile(
                icon: Icons.sync,
                iconFg: scheme.primary,
                iconBg: scheme.primary.withValues(alpha: 0.10),
                label: l10n.googleAccountsSync,
                onTap: () {
                  Navigator.of(ctx).pop();
                  _onSync(account);
                },
              ),
              ListMenuTile(
                icon: Icons.edit_outlined,
                iconFg: scheme.secondary,
                iconBg: scheme.secondary.withValues(alpha: 0.10),
                label: l10n.googleAccountsEditLabel,
                onTap: () {
                  Navigator.of(ctx).pop();
                  _onEditLabel(account);
                },
              ),
              ListMenuTile(
                icon: Icons.link_off,
                iconFg: scheme.error,
                iconBg: scheme.error.withValues(alpha: 0.10),
                label: l10n.googleAccountsDisconnect,
                onTap: () {
                  Navigator.of(ctx).pop();
                  _onDisconnect(account);
                },
              ),
            ],
          ),
        );
      },
    );
  }
}

// ─── Connecting FAB (spinner) ───────────────────────────────────────────

class _ConnectingFab extends StatelessWidget {
  const _ConnectingFab();

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      width: 56,
      height: 56,
      decoration: BoxDecoration(
        color: scheme.secondary,
        shape: BoxShape.circle,
        boxShadow: AppShadows.fabGold,
      ),
      child: const Center(
        child: SizedBox(
          width: 22,
          height: 22,
          child: CircularProgressIndicator(
            strokeWidth: 2.4,
            valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
          ),
        ),
      ),
    );
  }
}

// ─── Card ──────────────────────────────────────────────────────────────

class _GoogleAccountCard extends StatelessWidget {
  const _GoogleAccountCard({required this.account, required this.onTap});
  final GoogleAccount account;
  final VoidCallback onTap;

  String get _initial {
    final local = account.email.split('@').first;
    if (local.isEmpty) return '?';
    return local[0].toUpperCase();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final scheme = Theme.of(context).colorScheme;
    final q = account.quota;
    final total = q.total;
    final used = q.used;
    final pct = total > 0 ? (used / total).clamp(0.0, 1.0) : 0.0;
    final showQuota = total > 0;
    final showLabel = account.label.isNotEmpty && account.label != account.email;

    return Material(
      color: scheme.surfaceContainer,
      borderRadius: AppRadii.cardBorder,
      child: InkWell(
        borderRadius: AppRadii.cardBorder,
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            borderRadius: AppRadii.cardBorder,
            boxShadow: AppShadows.innerGlow(Theme.of(context).brightness),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: scheme.primaryContainer,
                  borderRadius: BorderRadius.circular(22),
                ),
                child: Center(
                  child: Text(
                    _initial,
                    style: AppTypography.headlineLgMobile.copyWith(
                      color: scheme.onPrimaryContainer,
                      fontSize: 20,
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      account.email,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: AppTypography.bodyMd.copyWith(
                        color: scheme.onSurface,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                    if (showLabel) ...[
                      const SizedBox(height: 2),
                      Text(
                        account.label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: AppTypography.bodySm.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                    if (showQuota) ...[
                      const SizedBox(height: 8),
                      ClipRRect(
                        borderRadius: AppRadii.pillBorder,
                        child: LinearProgressIndicator(
                          value: pct,
                          minHeight: 6,
                          backgroundColor: scheme.surfaceContainerHigh,
                          valueColor: AlwaysStoppedAnimation(
                            pct > 0.9 ? scheme.error : scheme.secondary,
                          ),
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${_humanSize(used)} / ${_humanSize(total)}',
                        style: AppTypography.metadata.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                      ),
                    ] else
                      Text(
                        l10n.googleAccountsLoadingQuota,
                        style: AppTypography.metadata.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              Icon(
                Icons.more_vert,
                color: scheme.onSurfaceVariant,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

// ─── States ────────────────────────────────────────────────────────────

class _LoadingState extends StatelessWidget {
  const _LoadingState();
  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      children: const [
        SizedBox(height: 120),
        Center(child: CircularProgressIndicator(strokeWidth: 2)),
      ],
    );
  }
}

class _ErrorState extends StatelessWidget {
  const _ErrorState({required this.message, required this.onRetry});
  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.containerPadding),
      children: [
        const SizedBox(height: 80),
        Center(
          child: Text(
            message,
            style: AppTypography.bodyMd.copyWith(
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        const SizedBox(height: 16),
        Center(
          child: TextButton(onPressed: onRetry, child: const Text('Retry')),
        ),
      ],
    );
  }
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({required this.l10n});
  final AppLocalizations l10n;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.symmetric(horizontal: AppSpacing.containerPadding),
      children: [
        const SizedBox(height: 48),
        EthericCard(
          padding: const EdgeInsets.all(AppSpacing.innerPadding),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Icon(
                Icons.account_circle_outlined,
                size: 48,
                color: scheme.primary,
              ),
              const SizedBox(height: 16),
              Text(
                l10n.googleAccountsEmpty,
                style: AppTypography.headlineLgMobile.copyWith(fontSize: 18),
              ),
              const SizedBox(height: 6),
              Text(
                l10n.googleAccountsEmptyDesc,
                style: AppTypography.bodyMd.copyWith(
                  color: scheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

// ─── Edit-label dialog ─────────────────────────────────────────────────

class _EditLabelDialog extends StatefulWidget {
  const _EditLabelDialog({required this.initial});
  final String initial;

  @override
  State<_EditLabelDialog> createState() => _EditLabelDialogState();
}

class _EditLabelDialogState extends State<_EditLabelDialog> {
  late final TextEditingController _ctrl =
      TextEditingController(text: widget.initial);

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final scheme = Theme.of(context).colorScheme;
    return AlertDialog(
      backgroundColor: scheme.surfaceContainer,
      title: Text(l10n.googleAccountsEditLabelTitle),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            l10n.googleAccountsEditLabelDesc,
            style: AppTypography.bodySm.copyWith(
              color: scheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 12),
          TextField(
            controller: _ctrl,
            autofocus: true,
            decoration: InputDecoration(
              hintText: widget.initial,
              border: const OutlineInputBorder(),
            ),
            textInputAction: TextInputAction.done,
            onSubmitted: (v) => Navigator.of(context).pop(v.trim()),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(l10n.commonCancel),
        ),
        TextButton(
          onPressed: () =>
              Navigator.of(context).pop(_ctrl.text.trim()),
          child: Text(l10n.googleAccountsEditLabelSave),
        ),
      ],
    );
  }
}

// ─── Helpers ───────────────────────────────────────────────────────────

String _humanSize(int bytes) {
  if (bytes <= 0) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  var v = bytes.toDouble();
  var i = 0;
  while (v >= 1024 && i < units.length - 1) {
    v /= 1024;
    i++;
  }
  return '${v.toStringAsFixed(v >= 10 || i == 0 ? 0 : 1)} ${units[i]}';
}
