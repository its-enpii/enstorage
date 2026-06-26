import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../state/auth_state.dart';
import '../../state/locale_state.dart';
import '../../state/theme_state.dart';
import '../../theme/radii.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/app_snackbar.dart';
import '../../widgets/app_dialog.dart';

class SettingsScreen extends ConsumerStatefulWidget {
  const SettingsScreen({super.key});

  @override
  ConsumerState<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends ConsumerState<SettingsScreen> {
  void comingSoon() {
    showAppSnackBar(
      context,
      AppLocalizations.of(context)!.settingsComingSoon,
      variant: AppSnackBarVariant.info,
    );
  }

  Future<void> _onEditName() async {
    final user = ref.read(authControllerProvider).user;
    final l10n = AppLocalizations.of(context)!;
    final newName = await showDialog<String>(
      context: context,
      builder: (_) => _EditNameDialog(initial: user?.name ?? ''),
    );
    if (newName == null || newName.isEmpty || newName == user?.name) return;
    // PATCH /auth/me requires both name and email; we only edit name, so
    // re-send the current email alongside the new name.
    final ok = await ref.read(authControllerProvider.notifier).updateMe(
          name: newName,
          email: user?.email ?? '',
        );
    if (!mounted) return;
    if (ok) {
      showAppSnackBar(context, l10n.settingsEditNameSuccess,
          variant: AppSnackBarVariant.success);
    }
    // On failure, `state.error` is set by the controller. Surface it
    // via a snackbar here too (the Profile header is too quiet to
    // notice the inline error on the screen).
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final user = ref.watch(authControllerProvider).user;
    final locale = ref.watch(localeControllerProvider);
    final themeMode = ref.watch(themeModeControllerProvider);
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.settingsTitle)),
      body: SafeArea(
        top: false,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.containerPadding,
            8,
            AppSpacing.containerPadding,
            120,
          ),
          children: [
            // Profile header
            const SizedBox(height: 8),
            _ProfileHeader(
              name: user?.name,
              email: user?.email,
              onEditName: _onEditName,
            ),
            const SizedBox(height: 32),

            // Account (password + Google)
            _SectionLabel(l10n.settingsAccountSection),
            const SizedBox(height: 12),
            _SettingGroup(
              children: [
                _SettingRow(
                  icon: Icons.lock_outline,
                  label: l10n.settingsChangePassword,
                  trailing: Icon(
                    Icons.chevron_right,
                    color: scheme.onSurfaceVariant,
                  ),
                  onTap: () => context.push('/settings/change-password'),
                ),
                _Divider(),
                _SettingRow(
                  icon: Icons.account_circle_outlined,
                  label: l10n.settingsGoogleAccounts,
                  trailing: Icon(
                    Icons.chevron_right,
                    color: scheme.onSurfaceVariant,
                  ),
                  onTap: () => context.push('/settings/google-accounts'),
                ),
              ],
            ),
            const SizedBox(height: 24),

            // Theme & Language
            _SectionLabel(l10n.settingsAppearance),
            const SizedBox(height: 12),
            _SettingGroup(
              children: [
                _SettingRow(
                  icon: Icons.dark_mode_outlined,
                  label: l10n.settingsTheme,
                  trailing: PopupMenuButton<ThemeMode>(
                    tooltip: l10n.settingsTheme,
                    onSelected: (mode) => ref
                        .read(themeModeControllerProvider.notifier)
                        .setMode(mode),
                    itemBuilder: (_) => [
                      PopupMenuItem(
                        value: ThemeMode.system,
                        child: Row(
                          children: [
                            const Icon(Icons.brightness_auto_outlined, size: 18),
                            const SizedBox(width: 12),
                            Text(l10n.settingsThemeSystem),
                          ],
                        ),
                      ),
                      PopupMenuItem(
                        value: ThemeMode.light,
                        child: Row(
                          children: [
                            const Icon(Icons.light_mode_outlined, size: 18),
                            const SizedBox(width: 12),
                            Text(l10n.settingsThemeLight),
                          ],
                        ),
                      ),
                      PopupMenuItem(
                        value: ThemeMode.dark,
                        child: Row(
                          children: [
                            const Icon(Icons.dark_mode_outlined, size: 18),
                            const SizedBox(width: 12),
                            Text(l10n.settingsThemeDark),
                          ],
                        ),
                      ),
                    ],
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          _themeLabel(themeMode, l10n),
                          style: AppTypography.bodyMd.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                        ),
                        Icon(Icons.expand_more,
                            color: scheme.onSurfaceVariant),
                      ],
                    ),
                  ),
                ),
                _Divider(),
                _SettingRow(
                  icon: Icons.language_outlined,
                  label: l10n.settingsLanguage,
                  trailing: PopupMenuButton<String>(
                    tooltip: l10n.settingsLanguage,
                    onSelected: (code) async {
                      await ref
                          .read(localeControllerProvider.notifier)
                          .setLocale(code == 'system' ? null : Locale(code));
                    },
                    itemBuilder: (_) => [
                      PopupMenuItem(
                        value: 'id',
                        child: Row(
                          children: [
                            const Text('🇮🇩', style: TextStyle(fontSize: 18)),
                            const SizedBox(width: 12),
                            Text(l10n.settingsLanguageIndonesian),
                          ],
                        ),
                      ),
                      PopupMenuItem(
                        value: 'en',
                        child: Row(
                          children: [
                            const Text('🇺🇸', style: TextStyle(fontSize: 18)),
                            const SizedBox(width: 12),
                            Text(l10n.settingsLanguageEnglish),
                          ],
                        ),
                      ),
                    ],
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(
                          locale == null
                              ? '🌐'
                              : (locale.languageCode == 'id' ? '🇮🇩' : '🇺🇸'),
                          style: const TextStyle(fontSize: 18),
                        ),
                        const SizedBox(width: 6),
                        Text(
                          locale?.languageCode.toUpperCase() ?? 'ID',
                          style: AppTypography.bodyMd.copyWith(
                            color: scheme.onSurfaceVariant,
                          ),
                        ),
                        Icon(Icons.expand_more,
                            color: scheme.onSurfaceVariant),
                      ],
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 24),

            // Legal (Privacy Policy + Terms of Service)
            _SectionLabel(l10n.settingsLegalSection),
            const SizedBox(height: 12),
            _SettingGroup(
              children: [
                _SettingRow(
                  icon: Icons.privacy_tip_outlined,
                  label: l10n.settingsLegalPrivacy,
                  trailing: Icon(
                    Icons.chevron_right,
                    color: scheme.onSurfaceVariant,
                  ),
                  onTap: () => context.push('/settings/legal/privacy'),
                ),
                _Divider(),
                _SettingRow(
                  icon: Icons.description_outlined,
                  label: l10n.settingsLegalTerms,
                  trailing: Icon(
                    Icons.chevron_right,
                    color: scheme.onSurfaceVariant,
                  ),
                  onTap: () => context.push('/settings/legal/terms'),
                ),
              ],
            ),
            const SizedBox(height: 24),

            // Sign out
            _SettingGroup(
              children: [
                _SettingRow(
                  icon: Icons.logout,
                  iconColor: scheme.error,
                  label: l10n.navLogout,
                  labelColor: scheme.error,
                  onTap: () async {
                    final confirmed = await showAppDialog<bool>(
                      context: context,
                      builder: (ctx) => AlertDialog(
                        title: Text(l10n.logoutConfirmTitle),
                        content: Text(l10n.logoutConfirmBody),
                        actions: [
                          TextButton(
                            onPressed: () => Navigator.of(ctx).pop(false),
                            child: Text(l10n.commonCancel),
                          ),
                          TextButton(
                            style: TextButton.styleFrom(
                                foregroundColor: Theme.of(ctx).colorScheme.error),
                            onPressed: () => Navigator.of(ctx).pop(true),
                            child: Text(l10n.logoutConfirmAction),
                          ),
                        ],
                      ),
                    );
                    if (confirmed != true) return;
                    await ref.read(authControllerProvider.notifier).logout();
                  },
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// ─── Profile header (avatar + name + email) ────────────────────────────

class _ProfileHeader extends StatelessWidget {
  const _ProfileHeader({
    required this.name,
    required this.email,
    required this.onEditName,
  });

  final String? name;
  final String? email;
  final VoidCallback onEditName;

  String get _initial {
    final n = (name ?? '').trim();
    if (n.isEmpty) return '?';
    return n[0].toUpperCase();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final scheme = Theme.of(context).colorScheme;
    return Column(
      children: [
        // Avatar — tap surface coming-soon for now; backend doesn't
        // support avatar upload yet.
        Material(
          color: scheme.primaryContainer,
          shape: const CircleBorder(),
          child: InkWell(
            customBorder: const CircleBorder(),
            onTap: () {
              showAppSnackBar(context, l10n.settingsComingSoon,
                  variant: AppSnackBarVariant.info);
            },
            child: Container(
              width: 88,
              height: 88,
              alignment: Alignment.center,
              child: Text(
                _initial,
                style: AppTypography.displayXl.copyWith(
                  fontSize: 36,
                  color: scheme.onPrimaryContainer,
                ),
              ),
            ),
          ),
        ),
        const SizedBox(height: 16),
        // Name — tap to edit via modal.
        Material(
          color: Colors.transparent,
          child: InkWell(
            borderRadius: BorderRadius.circular(8),
            onTap: onEditName,
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Flexible(
                    child: Text(
                      name ?? '—',
                      style: AppTypography.headlineLgMobile.copyWith(
                        color: scheme.onSurface,
                      ),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      textAlign: TextAlign.center,
                    ),
                  ),
                  const SizedBox(width: 6),
                  Icon(
                    Icons.edit_outlined,
                    size: 16,
                    color: scheme.onSurfaceVariant,
                  ),
                ],
              ),
            ),
          ),
        ),
        const SizedBox(height: 4),
        Text(
          email ?? '—',
          style: AppTypography.bodyMd.copyWith(
            color: scheme.onSurfaceVariant,
          ),
        ),
      ],
    );
  }
}

// ─── Edit-name modal (mirrors _EditLabelDialog pattern) ────────────────

class _EditNameDialog extends StatefulWidget {
  const _EditNameDialog({required this.initial});
  final String initial;

  @override
  State<_EditNameDialog> createState() => _EditNameDialogState();
}

class _EditNameDialogState extends State<_EditNameDialog> {
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
      title: Text(l10n.settingsEditNameTitle),
      content: TextField(
        controller: _ctrl,
        autofocus: true,
        textInputAction: TextInputAction.done,
        onSubmitted: (v) => Navigator.of(context).pop(v.trim()),
        decoration: InputDecoration(
          hintText: widget.initial,
          border: const OutlineInputBorder(),
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: Text(l10n.commonCancel),
        ),
        TextButton(
          onPressed: () => Navigator.of(context).pop(_ctrl.text.trim()),
          child: Text(l10n.settingsEditNameSave),
        ),
      ],
    );
  }
}

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);
  final String text;
  @override
  Widget build(BuildContext context) {
    return Text(
      text.toUpperCase(),
      style: AppTypography.labelSm.copyWith(
        color: Theme.of(context).colorScheme.onSurfaceVariant,
        letterSpacing: 0.05 * 12,
      ),
    );
  }
}

class _SettingGroup extends StatelessWidget {
  const _SettingGroup({required this.children});
  final List<Widget> children;
  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surfaceContainer,
        borderRadius: AppRadii.cardBorder,
      ),
      child: Column(children: children),
    );
  }
}

class _SettingRow extends StatelessWidget {
  const _SettingRow({
    required this.icon,
    required this.label,
    this.trailing,
    this.onTap,
    this.iconColor,
    this.labelColor,
  });
  final IconData icon;
  final String label;
  final Widget? trailing;
  final VoidCallback? onTap;
  final Color? iconColor;
  final Color? labelColor;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            children: [
              Icon(icon, color: iconColor ?? scheme.primary, size: 22),
              const SizedBox(width: 16),
              Expanded(
                child: Text(
                  label,
                  style: AppTypography.bodyMd.copyWith(
                    color: labelColor ?? scheme.onSurface,
                  ),
                ),
              ),
              if (trailing != null) trailing!,
            ],
          ),
        ),
      ),
    );
  }
}

class _Divider extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    return Container(
      height: 1,
      margin: const EdgeInsets.only(left: 54),
      color: Theme.of(context)
          .colorScheme
          .outlineVariant
          .withValues(alpha: 0.3),
    );
  }
}

String _themeLabel(ThemeMode mode, AppLocalizations l10n) {
  switch (mode) {
    case ThemeMode.light:
      return l10n.settingsThemeLight;
    case ThemeMode.dark:
      return l10n.settingsThemeDark;
    case ThemeMode.system:
      return l10n.settingsThemeSystem;
  }
}