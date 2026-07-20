import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../data/models/backup_settings.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../services/backup_service.dart';
import '../../services/backup_worker.dart';
import '../../state/backup_state.dart';
import '../../theme/radii.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_card.dart';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:permission_handler/permission_handler.dart';

import '../../data/models/backup_settings.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../services/backup_service.dart';
import '../../services/backup_worker.dart';
import '../../state/backup_state.dart';
import '../../theme/radii.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_card.dart';

/// Halaman settings Auto Backup.
class AutoBackupSettingsScreen extends ConsumerStatefulWidget {
  const AutoBackupSettingsScreen({super.key});

  @override
  ConsumerState<AutoBackupSettingsScreen> createState() =>
      _AutoBackupSettingsScreenState();
}

class _AutoBackupSettingsScreenState extends ConsumerState<AutoBackupSettingsScreen> {
  bool _batteryExempt = false;

  @override
  void initState() {
    super.initState();
    _checkBatteryOptimization();
  }

  Future<void> _checkBatteryOptimization() async {
    final status = await Permission.ignoreBatteryOptimizations.isGranted;
    if (mounted) {
      setState(() {
        _batteryExempt = status;
      });
      await ref
          .read(backupControllerProvider.notifier)
          .setBatteryOptimizationExempted(status);
    }
  }

  Future<void> _requestBatteryExemption() async {
    final status = await Permission.ignoreBatteryOptimizations.request();
    if (mounted) {
      setState(() {
        _batteryExempt = status.isGranted;
      });
      await ref
          .read(backupControllerProvider.notifier)
          .setBatteryOptimizationExempted(status.isGranted);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final progress = ref.watch(backupControllerProvider);
    final s = progress.settings;
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.settingsAutoBackupTitle)),
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
            // Master toggle
            _SectionLabel(l10n.autoBackupToggleSection),
            const SizedBox(height: 12),
            _SettingGroup(
              children: [
                SwitchListTile(
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 8,
                  ),
                  title: Text(l10n.settingsAutoBackupToggle,
                      style: AppTypography.bodyMd),
                  subtitle: Text(
                    s.enabled
                        ? l10n.autoBackupToggleOnSubtitle
                        : l10n.autoBackupToggleOffSubtitle,
                    style: AppTypography.bodySm.copyWith(
                      color: scheme.onSurfaceVariant,
                    ),
                  ),
                  value: s.enabled,
                  onChanged: (v) async {
                    await ref
                        .read(backupControllerProvider.notifier)
                        .setEnabled(v);
                  },
                ),
              ],
            ),
            const SizedBox(height: 24),

            // Background execution constraints
            _SectionLabel("Batasan Latar Belakang"),
            const SizedBox(height: 12),
            _SettingGroup(
              children: [
                SwitchListTile(
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 8,
                  ),
                  title: const Text("Hanya saat mengisi daya"),
                  subtitle: Text(
                    "Mencegah baterai terkuras saat backup",
                    style: AppTypography.bodySm.copyWith(
                      color: scheme.onSurfaceVariant,
                    ),
                  ),
                  value: s.requiresCharging,
                  onChanged: s.enabled
                      ? (v) async {
                          await ref
                              .read(backupControllerProvider.notifier)
                              .setRequiresCharging(v);
                        }
                      : null,
                ),
                const Divider(height: 1, indent: 16, endIndent: 16),
                SwitchListTile(
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 8,
                  ),
                  title: const Text("Berjalan tanpa batasan baterai"),
                  subtitle: Text(
                    _batteryExempt
                        ? "Sudah diizinkan berjalan di background"
                        : "Ketuk untuk mengizinkan backup tetap jalan saat HP mati/layar terkunci",
                    style: AppTypography.bodySm.copyWith(
                      color: _batteryExempt ? scheme.primary : scheme.onSurfaceVariant,
                    ),
                  ),
                  value: _batteryExempt,
                  onChanged: s.enabled && !_batteryExempt
                      ? (v) async {
                          if (v) await _requestBatteryExemption();
                        }
                      : null,
                ),
              ],
            ),
            const SizedBox(height: 24),

            // Network mode
            _SectionLabel(l10n.autoBackupNetworkSection),
            const SizedBox(height: 12),
            _SettingGroup(
              children: [
                RadioListTile<BackupNetworkMode>(
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 4,
                  ),
                  title: Text(l10n.autoBackupWifiOnly),
                  value: BackupNetworkMode.wifiOnly,
                  // ignore: deprecated_member_use
                  groupValue: s.mode,
                  onChanged: s.enabled
                      ? (v) {
                          if (v != null) {
                            ref
                                .read(backupControllerProvider.notifier)
                                .setMode(v);
                          }
                        }
                      : null,
                ),
                const Divider(height: 1, indent: 16, endIndent: 16),
                RadioListTile<BackupNetworkMode>(
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 4,
                  ),
                  title: Text(l10n.autoBackupWifiAndMobile),
                  value: BackupNetworkMode.wifiAndMobile,
                  // ignore: deprecated_member_use
                  groupValue: s.mode,
                  onChanged: s.enabled
                      ? (v) {
                          if (v != null) {
                            ref
                                .read(backupControllerProvider.notifier)
                                .setMode(v);
                          }
                        }
                      : null,
                ),
                const Divider(height: 1, indent: 16, endIndent: 16),
                RadioListTile<BackupNetworkMode>(
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 4,
                  ),
                  title: Text(l10n.autoBackupOff),
                  value: BackupNetworkMode.off,
                  // ignore: deprecated_member_use
                  groupValue: s.mode,
                  onChanged: s.enabled
                      ? (v) {
                          if (v != null) {
                            ref
                                .read(backupControllerProvider.notifier)
                                .setMode(v);
                          }
                        }
                      : null,
                ),
              ],
            ),
            const SizedBox(height: 24),

            // Interval preset
            _SectionLabel(l10n.autoBackupIntervalSection),
            const SizedBox(height: 12),
            _SettingGroup(
              children: [
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: Wrap(
                    spacing: 8,
                    children: BackupInterval.values
                        .map((i) => _IntervalChip(
                              interval: i,
                              selected: s.interval == i,
                              onTap: !s.enabled
                                  ? null
                                  : () async {
                                      await ref
                                          .read(backupControllerProvider.notifier)
                                          .setInterval(i);
                                    },
                            ))
                        .toList(),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 24),

            // Status + actions
            _SectionLabel(l10n.autoBackupStatusSection),
            const SizedBox(height: 12),
            EthericCard(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(
                        s.enabled
                            ? Icons.cloud_sync
                            : Icons.cloud_sync_outlined,
                        color: s.enabled ? scheme.primary : scheme.onSurfaceVariant,
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Text(
                          s.lastRunAt == null
                              ? l10n.autoBackupNeverRun
                              : l10n.autoBackupLastRun(
                                  _fmtDate(s.lastRunAt!),
                                ),
                          style: AppTypography.bodyMd,
                        ),
                      ),
                    ],
                  ),
                  if (s.lastError != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      s.lastError!,
                      style: AppTypography.bodySm.copyWith(
                        color: scheme.error,
                      ),
                    ),
                  ],
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: FilledButton.icon(
                          onPressed: s.enabled && !progress.isRunning
                              ? () async {
                                  // Bersihkan error merah langsung di UI
                                  await ref
                                      .read(backupControllerProvider.notifier)
                                      .clearError();
                                  // ignore: discarded_futures
                                  ref.read(backupServiceProvider).runOnce(
                                        triggeredByUserTap: true,
                                      );
                                }
                              : null,
                          icon: const Icon(Icons.play_arrow),
                          label: Text(l10n.autoBackupRunNow),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: progress.isRunning
                              ? () =>
                                  context.push('/settings/auto-backup/progress')
                              : null,
                          icon: const Icon(Icons.list),
                          label: Text(l10n.autoBackupViewProgress),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  String _fmtDate(DateTime d) {
    return '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')} '
        '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';
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
    return Material(
      borderRadius: AppRadii.cardBorder,
      color: Theme.of(context).colorScheme.surfaceContainer,
      clipBehavior: Clip.antiAlias,
      child: Column(children: children),
    );
  }
}

class _IntervalChip extends StatelessWidget {
  const _IntervalChip({
    required this.interval,
    required this.selected,
    required this.onTap,
  });

  final BackupInterval interval;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final hours = backupIntervalHours[interval] ?? 6;
    final label = switch (hours) {
      1 => l10n.autoBackupInterval1h,
      6 => l10n.autoBackupInterval6h,
      12 => l10n.autoBackupInterval12h,
      24 => l10n.autoBackupInterval24h,
      _ => '${hours}h',
    };
    final scheme = Theme.of(context).colorScheme;
    return InkWell(
      onTap: onTap,
      borderRadius: AppRadii.controlBorder,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        decoration: BoxDecoration(
          color: selected
              ? scheme.primary.withValues(alpha: 0.15)
              : scheme.surface,
          border: Border.all(
            color: selected ? scheme.primary : scheme.outlineVariant,
            width: selected ? 1.5 : 1,
          ),
          borderRadius: AppRadii.controlBorder,
        ),
        child: Text(
          label,
          style: AppTypography.bodyMd.copyWith(
            color: selected ? scheme.primary : scheme.onSurface,
            fontWeight: selected ? FontWeight.w600 : FontWeight.w400,
          ),
        ),
      ),
    );
  }
}