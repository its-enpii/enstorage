import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../state/auth_state.dart';
import '../../state/locale_state.dart';
import '../../theme/colors.dart';
import '../../theme/radii.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_card.dart';

class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context)!;
    final user = ref.watch(authControllerProvider).user;
    final locale = ref.watch(localeControllerProvider);

    void comingSoon() {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(l10n.settingsComingSoon)),
      );
    }

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
            // Profile
            _SectionLabel(l10n.settingsAccount),
            const SizedBox(height: 12),
            _SettingGroup(
              children: [
                _AccountRow(
                  label: l10n.settingsAccountName,
                  value: user?.name ?? '—',
                ),
                _Divider(),
                _AccountRow(
                  label: l10n.settingsAccountEmail,
                  value: user?.email ?? '—',
                ),
                _Divider(),
                _AccountRow(
                  label: l10n.settingsAccountRole,
                  value: l10n.settingsAccountOwner,
                ),
              ],
            ),
            const SizedBox(height: 24),

            // Google Accounts
            _SectionLabel(l10n.settingsGoogleAccounts),
            const SizedBox(height: 12),
            _SettingGroup(
              children: [
                _SettingRow(
                  icon: Icons.account_circle_outlined,
                  label: l10n.settingsGoogleAccounts,
                  trailing: const Icon(
                    Icons.chevron_right,
                    color: AppColors.onSurfaceVariant,
                  ),
                  onTap: () => context.push('/settings/google-accounts'),
                ),
              ],
            ),
            const SizedBox(height: 24),

            // API Keys
            _SectionLabel(l10n.settingsApiKeys),
            const SizedBox(height: 12),
            _SettingGroup(
              children: [
                _SettingRow(
                  icon: Icons.key_outlined,
                  label: l10n.settingsApiKeys,
                  trailing: const Icon(
                    Icons.chevron_right,
                    color: AppColors.onSurfaceVariant,
                  ),
                  onTap: comingSoon,
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
                  trailing: Text(
                    l10n.settingsThemeDark,
                    style: AppTypography.bodyMd.copyWith(
                      color: AppColors.onSurfaceVariant,
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
                            color: AppColors.onSurfaceVariant,
                          ),
                        ),
                        const Icon(Icons.expand_more,
                            color: AppColors.onSurfaceVariant),
                      ],
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 24),

            // Notifications (next feature)
            _SectionLabel(l10n.settingsNotifications),
            const SizedBox(height: 12),
            _SettingGroup(
              children: [
                _SettingRow(
                  icon: Icons.notifications_outlined,
                  label: l10n.settingsNotifUpload,
                  trailing: const Icon(
                    Icons.chevron_right,
                    color: AppColors.onSurfaceVariant,
                  ),
                  onTap: comingSoon,
                ),
                _Divider(),
                _SettingRow(
                  icon: Icons.notifications_outlined,
                  label: l10n.settingsNotifQuota,
                  trailing: const Icon(
                    Icons.chevron_right,
                    color: AppColors.onSurfaceVariant,
                  ),
                  onTap: comingSoon,
                ),
                _Divider(),
                _SettingRow(
                  icon: Icons.shield_outlined,
                  label: l10n.settingsNotifSecurity,
                  trailing: const Icon(
                    Icons.chevron_right,
                    color: AppColors.onSurfaceVariant,
                  ),
                  onTap: comingSoon,
                ),
              ],
            ),
            const SizedBox(height: 24),

            // Sign out
            _SettingGroup(
              children: [
                _SettingRow(
                  icon: Icons.logout,
                  iconColor: AppColors.error,
                  label: l10n.navLogout,
                  labelColor: AppColors.error,
                  onTap: () async {
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

class _SectionLabel extends StatelessWidget {
  const _SectionLabel(this.text);
  final String text;
  @override
  Widget build(BuildContext context) {
    return Text(
      text.toUpperCase(),
      style: AppTypography.labelSm.copyWith(
        color: AppColors.onSurfaceVariant,
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
        color: AppColors.surface,
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
    this.iconColor = AppColors.primary,
    this.labelColor,
  });
  final IconData icon;
  final String label;
  final Widget? trailing;
  final VoidCallback? onTap;
  final Color iconColor;
  final Color? labelColor;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            children: [
              Icon(icon, color: iconColor, size: 22),
              const SizedBox(width: 16),
              Expanded(
                child: Text(
                  label,
                  style: AppTypography.bodyMd.copyWith(
                    color: labelColor ?? AppColors.onSurface,
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

class _AccountRow extends StatelessWidget {
  const _AccountRow({required this.label, required this.value});
  final String label;
  final String value;
  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
      child: Row(
        children: [
          Expanded(
            child: Text(
              label,
              style: AppTypography.bodyMd.copyWith(
                color: AppColors.onSurfaceVariant,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Flexible(
            child: Text(
              value,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              textAlign: TextAlign.end,
              style: AppTypography.bodyMd.copyWith(
                color: AppColors.onSurface,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
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
      color: AppColors.outlineVariant.withValues(alpha: 0.3),
    );
  }
}
