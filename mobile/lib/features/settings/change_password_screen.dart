import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../state/auth_state.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_button.dart';
import '../../widgets/etheric_text_field.dart';
import '../../widgets/app_snackbar.dart';

/// Rotate the current user's password. Pushed from
/// `/settings/edit-profile`. Client-side checks new == confirm and
/// length ≥ 8 before hitting the backend; the backend's `current_password`
/// check is surfaced via `state.error` on failure.
class ChangePasswordScreen extends ConsumerStatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  ConsumerState<ChangePasswordScreen> createState() =>
      _ChangePasswordScreenState();
}

class _ChangePasswordScreenState
    extends ConsumerState<ChangePasswordScreen> {
  final _current = TextEditingController();
  final _next = TextEditingController();
  final _confirm = TextEditingController();

  String? _mismatchError;
  String? _tooShortError;

  @override
  void dispose() {
    _current.dispose();
    _next.dispose();
    _confirm.dispose();
    super.dispose();
  }

  bool _validate() {
    final l10n = AppLocalizations.of(context)!;
    final next = _next.text;
    final confirm = _confirm.text;

    final tooShort = next.isNotEmpty && next.length < 8;
    final mismatch = confirm.isNotEmpty && next != confirm;

    setState(() {
      _tooShortError = tooShort ? l10n.settingsChangePasswordTooShort : null;
      _mismatchError = mismatch ? l10n.settingsChangePasswordMismatch : null;
    });

    // `current` and the empty case for `next`/`confirm` are enforced
    // server-side, matching the RegisterScreen pattern.
    return next.isNotEmpty &&
        confirm.isNotEmpty &&
        !tooShort &&
        !mismatch;
  }

  Future<void> _submit() async {
    if (!_validate()) return;
    final l10n = AppLocalizations.of(context)!;
    final ok =
        await ref.read(authControllerProvider.notifier).changePassword(
              current: _current.text,
              next: _next.text,
            );
    if (!mounted) return;
    if (ok) {
      showAppSnackBar(context, l10n.settingsChangePasswordSuccess,
          variant: AppSnackBarVariant.success);
      context.pop();
    }
    // On failure, `state.error` is set by the controller (backend
    // validation messages, e.g. "Current password is incorrect.") and
    // rendered below the form by the build() method.
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final auth = ref.watch(authControllerProvider);
    final scheme = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(title: Text(l10n.settingsChangePassword)),
      body: SafeArea(
        top: false,
        child: ListView(
          padding: const EdgeInsets.symmetric(
            horizontal: AppSpacing.containerPadding,
          ),
          children: [
            const SizedBox(height: 8),
            Text(
              l10n.settingsChangePasswordSubtitle,
              style: AppTypography.bodyMd.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 24),
            EthericTextField(
              controller: _current,
              label: l10n.settingsChangePasswordCurrent,
              hint: l10n.settingsChangePasswordCurrent,
              prefixIcon: Icons.lock_outline,
              obscureText: true,
              textInputAction: TextInputAction.next,
            ),
            const SizedBox(height: 16),
            EthericTextField(
              controller: _next,
              label: l10n.settingsChangePasswordNew,
              hint: l10n.settingsChangePasswordNew,
              prefixIcon: Icons.lock_outline,
              obscureText: true,
              textInputAction: TextInputAction.next,
              errorText: _tooShortError,
            ),
            const SizedBox(height: 16),
            EthericTextField(
              controller: _confirm,
              label: l10n.settingsChangePasswordConfirm,
              hint: l10n.settingsChangePasswordConfirm,
              prefixIcon: Icons.lock_outline,
              obscureText: true,
              textInputAction: TextInputAction.done,
              onSubmitted: (_) => _submit(),
              errorText: _mismatchError,
            ),
            if (auth.error != null) ...[
              const SizedBox(height: 16),
              Text(
                auth.error!,
                style: AppTypography.bodyMd.copyWith(color: scheme.error),
                textAlign: TextAlign.center,
              ),
            ],
            const SizedBox(height: 32),
            EthericButton(
              label: l10n.commonSave,
              onPressed: auth.loading ? null : _submit,
              loading: auth.loading,
              expanded: true,
            ),
          ],
        ),
      ),
    );
  }
}