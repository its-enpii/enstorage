import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/models/backup_progress.dart';
import '../../data/models/backup_queue_item.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../services/backup_service.dart';
import '../../state/backup_state.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_card.dart';

/// Progress screen Auto Backup — 3 collapsible section (Selesai, Sedang,
/// Belum) + overall progress bar + Pause/Resume + tombol cancel (×) +
/// info folder tujuan.
///
/// Source: `BackupController` state. Subscribe via
/// `ref.watch(backupControllerProvider)`.
class BackupProgressScreen extends ConsumerWidget {
  const BackupProgressScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context)!;
    final progress = ref.watch(backupControllerProvider);

    final queue = progress.queue;
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.autoBackupProgressTitle)),
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
            // ─── Header: overall progress + buttons ─────────────
            EthericCard(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text(
                          _summaryLabel(progress, l10n),
                          style: AppTypography.bodyMd,
                        ),
                      ),
                      // Spinner hanya saat aktif. Saat paused, isRunning tetap
                      // true (loop masih hidup menunggu resume), tapi
                      // user expects animasi berhenti — tambahkan
                      // guard `!paused`.
                      if (progress.isRunning && !progress.paused)
                        const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(strokeWidth: 2),
                        ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(8),
                    child: LinearProgressIndicator(
                      value: progress.overallFraction,
                      minHeight: 10,
                      backgroundColor: scheme.surfaceContainerHigh,
                    ),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      _ProgressButton(
                        progress: progress,
                        l10n: l10n,
                        ref: ref,
                      ),
                      const Spacer(),
                      IconButton(
                        tooltip: l10n.autoBackupProgressCancel,
                        onPressed: progress.isRunning
                            ? () async {
                                await ref
                                    .read(backupServiceProvider)
                                    .cancel();
                              }
                            : null,
                        icon: const Icon(Icons.close),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),

            // ─── File list (flat — semua status tampil di sini) ─────
            if (queue.isNotEmpty) ...[
              const SizedBox(height: 8),
              ...queue.map((it) => Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: _ItemRow(item: it),
                  )),
            ],

            // Empty state
            if (queue.isEmpty)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 80),
                child: Center(
                  child: Column(
                    children: [
                      Icon(
                        Icons.cloud_sync_outlined,
                        size: 56,
                        color: scheme.onSurfaceVariant,
                      ),
                      const SizedBox(height: 12),
                      Text(
                        l10n.autoBackupProgressEmpty,
                        style: AppTypography.bodyMd.copyWith(
                          color: scheme.onSurfaceVariant,
                        ),
                      ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  String _summaryLabel(BackupProgress progress, AppLocalizations l10n) {
    if (progress.isRunning && progress.queue.isEmpty) {
      return l10n.autoBackupProgressScanning;
    }
    final totals = progress.totals;
    final total = totals.done + totals.skipped + totals.failed +
        totals.inProgress + totals.pending;
    if (total == 0) return l10n.autoBackupProgressIdle;
    return l10n.autoBackupProgressRunningSummary(
      totals.done + totals.skipped,
      total,
    );
  }
}

class _ItemRow extends StatelessWidget {
  const _ItemRow({required this.item});

  final BackupQueueItem item;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final scheme = Theme.of(context).colorScheme;

    // Resolve status → icon, label, warna.
    final IconData icon;
    final Color color;
    final String label;
    final bool showProgress = item.status == BackupItemStatus.uploading &&
        item.progressFraction > 0;
    switch (item.status) {
      case BackupItemStatus.pending:
        icon = Icons.schedule;
        color = scheme.onSurfaceVariant;
        label = l10n.autoBackupItemPending;
        break;
      case BackupItemStatus.hashing:
        icon = Icons.fingerprint;
        color = scheme.tertiary;
        label = l10n.autoBackupItemHashing;
        break;
      case BackupItemStatus.uploading:
        icon = Icons.cloud_upload_outlined;
        color = scheme.primary;
        label = showProgress
            ? '${(item.progressFraction * 100).toInt()}%'
            : 'Mengirim…';
        break;
      case BackupItemStatus.done:
        icon = Icons.check_circle_rounded;
        color = scheme.primary;
        label = l10n.autoBackupItemDone;
        break;
      case BackupItemStatus.skipped:
        icon = Icons.skip_next_rounded;
        color = scheme.secondary;
        label = l10n.autoBackupItemSkipped;
        break;
      case BackupItemStatus.failed:
        icon = Icons.error_rounded;
        color = scheme.error;
        label = l10n.autoBackupItemFailed;
        break;
    }

    final path = item.targetRelativePath ?? '';
    final folder = () {
      if (path.isEmpty) {
        final absParts = item.absolutePath.split('/');
        return absParts.length > 1
            ? absParts[absParts.length - 2]
            : 'Auto Backup';
      }
      final segs = path.split('/');
      return segs.length > 1
          ? segs.sublist(0, segs.length - 1).join('/')
          : 'Auto Backup';
    }();

    return EthericCard(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Icon(icon, color: color, size: 20),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  item.displayName,
                  style: AppTypography.bodyMd,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(
                  color: color.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(
                  label,
                  style: AppTypography.labelSm.copyWith(
                    color: color,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          Text(
            '$folder • ${_fmtBytes(item.sizeBytes)}',
            style: AppTypography.bodySm.copyWith(
              color: scheme.onSurfaceVariant,
            ),
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          if (showProgress) ...[
            const SizedBox(height: 6),
            ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: LinearProgressIndicator(
                value: item.progressFraction,
                minHeight: 4,
                backgroundColor: scheme.surfaceContainerHigh,
              ),
            ),
          ],
          if (item.status == BackupItemStatus.failed &&
              item.errorMessage != null) ...[
            const SizedBox(height: 6),
            Text(
              item.errorMessage!,
              style: AppTypography.bodySm.copyWith(color: scheme.error),
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ],
      ),
    );
  }

  String _fmtBytes(int b) {
    if (b < 1024) return '${b}B';
    if (b < 1024 * 1024) return '${(b / 1024).toStringAsFixed(1)}KB';
    if (b < 1024 * 1024 * 1024) {
      return '${(b / 1024 / 1024).toStringAsFixed(1)}MB';
    }
    return '${(b / 1024 / 1024 / 1024).toStringAsFixed(1)}GB';
  }
}

class _ProgressButton extends StatelessWidget {
  const _ProgressButton({
    required this.progress,
    required this.l10n,
    required this.ref,
  });

  final BackupProgress progress;
  final AppLocalizations l10n;
  final WidgetRef ref;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    if (progress.paused) {
      return FilledButton.icon(
        onPressed: () async {
          await ref.read(backupServiceProvider).resume();
        },
        icon: const Icon(Icons.play_arrow),
        label: Text(l10n.autoBackupProgressResume),
        style: FilledButton.styleFrom(
          backgroundColor: scheme.primary,
        ),
      );
    }
    if (progress.isRunning) {
      final isScanning = progress.queue.isEmpty;
      return OutlinedButton.icon(
        onPressed: isScanning
            ? null
            : () async {
                await ref.read(backupServiceProvider).pause();
              },
        icon: const Icon(Icons.pause),
        label: Text(l10n.autoBackupProgressPause),
      );
    }
    // Idle — tombol "Run now" untuk trigger backup manual dari progress screen.
    return FilledButton.icon(
      onPressed: () async {
        await ref.read(backupServiceProvider).runOnce(
              triggeredByUserTap: true,
            );
      },
      icon: const Icon(Icons.play_arrow),
      label: Text(l10n.autoBackupRunNow),
      style: FilledButton.styleFrom(
        backgroundColor: scheme.primary,
      ),
    );
  }
}