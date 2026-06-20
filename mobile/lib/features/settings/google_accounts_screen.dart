import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_sign_in/google_sign_in.dart';

import '../../data/models/google_account.dart';
import '../../data/repositories/google_accounts_repository.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../theme/colors.dart';
import '../../theme/radii.dart';
import '../../theme/shadows.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_card.dart';
import '../../widgets/etheric_fab.dart';

/// Web OAuth client ID — dipakai sebagai `serverClientId` untuk Google
/// Sign-In native SDK. Backend pakai `client_secret` milik client ini
/// untuk menukar `server_auth_code` jadi token.
const String _kWebClientId =
    '821312365202-gkdfheld9sa8btmgjorpa26e3pn57lvo.apps.googleusercontent.com';

/// Scopes yang diminta saat user hubungkan akun. `drive.file` kasih
/// akses baca/tulis ke file yang app buat di Drive (cukup untuk vault).
const List<String> _kScopes = <String>[
  'https://www.googleapis.com/auth/drive.file',
];

/// Connected Google accounts. Three responsibilities:
///
/// 1. Show the list of accounts (with quota per row).
/// 2. Connect a new account via `google_sign_in` native SDK —
///    Google account picker muncul inline (bukan browser), lalu
///    `serverAuthCode` dikirim ke backend untuk ditukar jadi token.
/// 3. Per-account actions via bottom sheet: Sync Quota, Edit Label,
///    Disconnect.
class GoogleAccountsScreen extends ConsumerStatefulWidget {
  const GoogleAccountsScreen({super.key});

  @override
  ConsumerState<GoogleAccountsScreen> createState() =>
      _GoogleAccountsScreenState();
}

class _GoogleAccountsScreenState extends ConsumerState<GoogleAccountsScreen> {
  @override
  void initState() {
    super.initState();
    _initGoogleSignIn();
  }

  Future<void> _initGoogleSignIn() async {
    try {
      await GoogleSignIn.instance.initialize(
        serverClientId: _kWebClientId,
      );
    } catch (_) {
      // Initialization failure (mis. emulator tanpa Google Play
      // Services) — handled lazily on first `_onConnect()` call.
    }
  }

  Future<void> _onConnect() async {
    final l10n = AppLocalizations.of(context)!;
    final repo = ref.read(googleAccountsRepositoryProvider);
    final messenger = ScaffoldMessenger.of(context);

    try {
      // 1. Native Google account picker + sign-in.
      final account = await GoogleSignIn.instance.authenticate();

      // 2. Request server-side auth code with Drive scope. This
      //    returns a one-time code yang backend tukar jadi token.
      final serverAuth =
          await account.authorizationClient.authorizeServer(_kScopes);
      final code = serverAuth?.serverAuthCode;
      if (code == null) {
        if (!mounted) return;
        messenger.showSnackBar(
          SnackBar(content: Text(l10n.googleAccountsExchangeFailed)),
        );
        return;
      }

      // 3. Exchange with backend.
      await repo.exchangeServerAuthCode(code: code);
      ref.invalidate(googleAccountsProvider);
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(content: Text(l10n.googleAccountsExchangeSuccess)),
      );
    } on GoogleSignInException catch (e) {
      // User cancelled at the picker, atau Play Services unavailable.
      // Silent untuk cancellation — explicit error untuk yang lain.
      if (e.code == GoogleSignInExceptionCode.canceled) return;
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(content: Text(l10n.googleAccountsExchangeFailed)),
      );
    } catch (_) {
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(content: Text(l10n.googleAccountsExchangeFailed)),
      );
    }
  }

  Future<void> _onSync(GoogleAccount account) async {
    final l10n = AppLocalizations.of(context)!;
    final messenger = ScaffoldMessenger.of(context);
    final repo = ref.read(googleAccountsRepositoryProvider);
    try {
      await repo.syncQuota(account.id);
      ref.invalidate(googleAccountsProvider);
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(content: Text(l10n.googleAccountsSyncSuccess)),
      );
    } catch (_) {
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(content: Text(l10n.googleAccountsSyncFailed)),
      );
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
    final messenger = ScaffoldMessenger.of(context);
    try {
      await repo.updateLabel(account.id, newLabel);
      ref.invalidate(googleAccountsProvider);
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(content: Text(l10n.googleAccountsLabelUpdated)),
      );
    } catch (_) {
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(content: Text(l10n.googleAccountsLabelFailed)),
      );
    }
  }

  Future<void> _onDisconnect(GoogleAccount account) async {
    final l10n = AppLocalizations.of(context)!;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: AppColors.surface,
        title: Text(l10n.googleAccountsDisconnectTitle),
        content: Text(l10n.googleAccountsDisconnectBody),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: Text(l10n.commonCancel),
          ),
          TextButton(
            style: TextButton.styleFrom(foregroundColor: AppColors.error),
            onPressed: () => Navigator.of(ctx).pop(true),
            child: Text(l10n.googleAccountsDisconnectConfirm),
          ),
        ],
      ),
    );
    if (confirmed != true) return;

    final repo = ref.read(googleAccountsRepositoryProvider);
    final messenger = ScaffoldMessenger.of(context);
    try {
      await repo.disconnect(account.id);
      ref.invalidate(googleAccountsProvider);
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(content: Text(l10n.googleAccountsDisconnected)),
      );
    } catch (_) {
      if (!mounted) return;
      messenger.showSnackBar(
        SnackBar(content: Text(l10n.googleAccountsDisconnectFailed)),
      );
    }
  }

  void _showActionSheet(GoogleAccount account) {
    final l10n = AppLocalizations.of(context)!;
    showModalBottomSheet<Widget>(
      context: context,
      backgroundColor: AppColors.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return SafeArea(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              ListTile(
                leading: const Icon(Icons.sync),
                title: Text(l10n.googleAccountsSync),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _onSync(account);
                },
              ),
              ListTile(
                leading: const Icon(Icons.edit_outlined),
                title: Text(l10n.googleAccountsEditLabel),
                onTap: () {
                  Navigator.of(ctx).pop();
                  _onEditLabel(account);
                },
              ),
              ListTile(
                leading: const Icon(
                  Icons.link_off,
                  color: AppColors.error,
                ),
                title: Text(
                  l10n.googleAccountsDisconnect,
                  style: const TextStyle(color: AppColors.error),
                ),
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

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final accounts = ref.watch(googleAccountsProvider);

    return Scaffold(
      appBar: AppBar(title: Text(l10n.googleAccountsTitle)),
      body: SafeArea(
        top: false,
        child: RefreshIndicator(
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
      ),
      floatingActionButton: EthericFab(onTap: _onConnect),
      floatingActionButtonLocation: FloatingActionButtonLocation.endFloat,
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
    final q = account.quota;
    final total = q.total;
    final used = q.used;
    final pct = total > 0 ? (used / total).clamp(0.0, 1.0) : 0.0;
    final showQuota = total > 0;
    // Backend seeds `label` with the email on connect, so hide the
    // duplicate label row when it still equals the email.
    final showLabel = account.label.isNotEmpty && account.label != account.email;

    return Material(
      color: AppColors.surface,
      borderRadius: AppRadii.cardBorder,
      child: InkWell(
        borderRadius: AppRadii.cardBorder,
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            borderRadius: AppRadii.cardBorder,
            boxShadow: AppShadows.innerGlow,
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: AppColors.primaryContainer,
                  borderRadius: BorderRadius.circular(22),
                ),
                child: Center(
                  child: Text(
                    _initial,
                    style: AppTypography.headlineLgMobile.copyWith(
                      color: AppColors.onPrimaryContainer,
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
                        color: AppColors.onSurface,
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
                          color: AppColors.onSurfaceVariant,
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
                          backgroundColor: AppColors.surfaceHigh,
                          valueColor: AlwaysStoppedAnimation(
                            pct > 0.9 ? AppColors.error : AppColors.secondary,
                          ),
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        '${_humanSize(used)} / ${_humanSize(total)}',
                        style: AppTypography.metadata.copyWith(
                          color: AppColors.onSurfaceVariant,
                        ),
                      ),
                    ] else
                      Text(
                        l10n.googleAccountsLoadingQuota,
                        style: AppTypography.metadata.copyWith(
                          color: AppColors.onSurfaceVariant,
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(width: 8),
              const Icon(
                Icons.more_vert,
                color: AppColors.onSurfaceVariant,
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
              color: AppColors.onSurfaceVariant,
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
              const Icon(
                Icons.account_circle_outlined,
                size: 48,
                color: AppColors.primary,
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
                  color: AppColors.onSurfaceVariant,
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
    return AlertDialog(
      backgroundColor: AppColors.surface,
      title: Text(l10n.googleAccountsEditLabelTitle),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            l10n.googleAccountsEditLabelDesc,
            style: AppTypography.bodySm.copyWith(
              color: AppColors.onSurfaceVariant,
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
