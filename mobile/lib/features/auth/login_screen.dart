import 'dart:async';

import 'package:flutter/foundation.dart' show debugPrint;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show PlatformException;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:flutter_svg/flutter_svg.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../state/auth_state.dart';
import '../../state/theme_state.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_button.dart';
import '../../widgets/app_snackbar.dart';

/// OAuth scopes requested during sign-in.
const List<String> _kScopes = <String>[
  'https://www.googleapis.com/auth/drive.file',
];

/// Web OAuth client ID — same as in google_accounts_screen.dart.
const String _kWebClientId =
    'REDACTED_CLIENT_ID';

/// Single auth screen — Google Sign-In only.
///
/// Replaces the old email/password login + register screens.
/// Tapping the button triggers `google_sign_in` v6.x native SDK,
/// sends the `server_auth_code` to `POST /auth/google`, which
/// either logs in or registers + auto-links the Google account.
class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  bool _signingIn = false;

  final GoogleSignIn _googleSignIn = GoogleSignIn(
    scopes: _kScopes,
    serverClientId: _kWebClientId,
  );

  Future<void> _onGoogleSignIn() async {
    if (_signingIn) return;
    setState(() => _signingIn = true);

    final l10n = AppLocalizations.of(context)!;

    try {
      final GoogleSignInAccount? account = await _googleSignIn.signIn();
      if (account == null) {
        debugPrint('[auth] signIn returned null (user cancelled)');
        return;
      }

      final String? code = account.serverAuthCode;
      if (code == null || code.isEmpty) {
        debugPrint('[auth] serverAuthCode null/empty');
        if (!mounted) return;
        showAppSnackBar(
          context,
          l10n.authGoogleFailed,
          variant: AppSnackBarVariant.error,
        );
        return;
      }

      // Sign out native session — source of truth is our backend.
      unawaited(_googleSignIn.signOut());

      final ok = await ref.read(authControllerProvider.notifier).googleLogin(code);
      if (!ok && mounted) {
        showAppSnackBar(
          context,
          ref.read(authControllerProvider).error ?? l10n.authGoogleFailed,
          variant: AppSnackBarVariant.error,
        );
      }
      // On success the router auto-switches to the home shell.
    } on PlatformException catch (e) {
      debugPrint('[auth] PlatformException: code=${e.code} message=${e.message}');
      if (e.code == 'sign_in_canceled') return;
      if (!mounted) return;
      showAppSnackBar(
        context,
        l10n.authGoogleFailed,
        variant: AppSnackBarVariant.error,
      );
    } catch (e, st) {
      debugPrint('[auth] UNEXPECTED: $e\n$st');
      if (!mounted) return;
      showAppSnackBar(
        context,
        l10n.authGoogleFailed,
        variant: AppSnackBarVariant.error,
      );
    } finally {
      if (mounted) setState(() => _signingIn = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final auth = ref.watch(authControllerProvider);
    final scheme = Theme.of(context).colorScheme;

    final themeMode = ref.watch(themeModeControllerProvider);
    final isDark = themeMode == ThemeMode.dark ||
        (themeMode == ThemeMode.system &&
            MediaQuery.platformBrightnessOf(context) == Brightness.dark);

    return Scaffold(
      body: SafeArea(
        child: Stack(
          children: [
            Center(
              child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(
              horizontal: AppSpacing.containerPadding,
            ),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(
                  l10n.appName,
                  textAlign: TextAlign.center,
                  style: AppTypography.displayXl.copyWith(
                    color: scheme.primary,
                    fontSize: 36,
                  ),
                ),
                const SizedBox(height: 12),
                Text(
                  l10n.authLoginTitle,
                  textAlign: TextAlign.center,
                  style: AppTypography.headlineLgMobile,
                ),
                const SizedBox(height: 8),
                Text(
                  l10n.authGoogleSubtitle,
                  textAlign: TextAlign.center,
                  style: AppTypography.bodyMd.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
                const SizedBox(height: 48),
                if (auth.error != null) ...[
                  Text(
                    auth.error!,
                    style: AppTypography.bodyMd.copyWith(color: scheme.error),
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 16),
                ],
                _GoogleSignInButton(
                  onPressed: auth.loading ? null : _onGoogleSignIn,
                  loading: _signingIn || auth.loading,
                  label: l10n.authGoogleSignIn,
                ),
                const SizedBox(height: 24),
                _TermsText(l10n: l10n),
              ],
            ),
          ),
        ),
            // Dark/light toggle — top right
            Positioned(
              top: 8,
              right: 8,
              child: IconButton(
                icon: Icon(
                  isDark ? Icons.light_mode_outlined : Icons.dark_mode_outlined,
                  color: scheme.onSurfaceVariant,
                ),
                tooltip: isDark
                    ? l10n.settingsThemeLight
                    : l10n.settingsThemeDark,
                onPressed: () {
                  ref.read(themeModeControllerProvider.notifier).setMode(
                        isDark ? ThemeMode.light : ThemeMode.dark,
                      );
                },
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _GoogleSignInButton extends StatelessWidget {
  const _GoogleSignInButton({
    required this.onPressed,
    required this.loading,
    required this.label,
  });

  final VoidCallback? onPressed;
  final bool loading;
  final String label;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return SizedBox(
      width: double.infinity,
      height: 52,
      child: OutlinedButton.icon(
        onPressed: onPressed,
        style: OutlinedButton.styleFrom(
          side: BorderSide(color: scheme.outline),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
          ),
          backgroundColor: scheme.surface,
        ),
        icon: loading
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : SvgPicture.asset(
                'assets/icon/google.svg',
                width: 20,
                height: 20,
                placeholderBuilder: (_) => const SizedBox(
                  width: 20,
                  height: 20,
                  child: CircularProgressIndicator(strokeWidth: 1.5),
                ),
              ),
        label: Text(
          label,
          style: AppTypography.bodyMd.copyWith(
            fontWeight: FontWeight.w600,
            color: scheme.onSurface,
          ),
        ),
      ),
    );
  }
}

class _TermsText extends StatelessWidget {
  const _TermsText({required this.l10n});
  final AppLocalizations l10n;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16),
      child: GestureDetector(
        onTap: () => context.push('/terms'),
        child: RichText(
          textAlign: TextAlign.center,
          text: TextSpan(
            style: AppTypography.bodySm.copyWith(
              color: scheme.onSurfaceVariant,
            ),
            children: [
              TextSpan(text: l10n.termsAgreePrefix),
              TextSpan(
                text: l10n.termsLink,
                style: AppTypography.bodySm.copyWith(
                  color: scheme.primary,
                  decoration: TextDecoration.underline,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
