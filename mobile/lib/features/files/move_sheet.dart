import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/models/file_item.dart';
import '../../data/models/folder.dart';
import '../../data/repositories/files_repository.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../state/files_state.dart';
import '../../theme/radii.dart';
import '../../theme/typography.dart';
import '../../widgets/app_snackbar.dart';
import '../../widgets/nav_aware_sheet.dart';

/// Bottom sheet untuk memilih folder tujuan saat memindahkan satu atau
/// banyak file. Tap folder → langsung move + close. Tap "Root (My Files)"
/// → pindahkan ke folder_id = null.
///
/// Cegah picking folder yang sedang jadi source (kalau semua file berasal
/// dari folder yang sama — kasus bulk move dari satu folder). Kalau file
/// berasal dari folder berbeda (mis. dari search result), tampilkan semua
/// folder.
Future<void> showMoveSheet(
  BuildContext context,
  WidgetRef ref, {
  required List<FileItem> files,
  required String? currentFolderId,
}) async {
  if (files.isEmpty) return;
  final l10n = AppLocalizations.of(context)!;
  final repo = ref.read(filesRepositoryProvider);

  // Fetch seluruh folder user (flat). Endpoint tidak dipaginate untuk picker
  // ini, dan jumlah folder biasanya kecil (< ratusan). Kalau nanti banyak,
  // bisa di-debounce + search field.
  final List<Folder> folders;
  try {
    final res = await repo.pagedFolders(perPage: 100);
    folders = res.items;
  } catch (_) {
    if (context.mounted) {
      showAppSnackBar(context, l10n.filesMoveLoadFoldersFailed,
          variant: AppSnackBarVariant.error);
    }
    return;
  }

  if (!context.mounted) return;

  final target = await showModalBottomSheet<MoveTarget>(
    context: context,
    showDragHandle: false,
    isScrollControlled: true,
    builder: (ctx) {
      return NavAwareSheet(
        child: _MoveSheet(
          folders: folders,
          currentFolderId: currentFolderId,
          filesCount: files.length,
          fileLabel: files.length == 1 ? files.first.name : null,
        ),
      );
    },
  );
  if (target == null) return; // user dismissed

  // Eksekusi move sequential per file (backend tidak punya bulk-move).
  int success = 0;
  int renamedCount = 0;
  final failedIds = <String>[];
  for (final f in files) {
    try {
      // ignore: discarded_futures
      await ref.read(filesRepositoryProvider).moveFile(f.id, folderId: target.folderId);
      success += 1;
    } catch (_) {
      failedIds.add(f.id);
    }
  }

  if (!context.mounted) return;

  // Update list lokal untuk file yang berhasil (file pindah keluar dari
  // folder ini, jadi dihapus dari state).
  if (success > 0 && currentFolderId != null) {
    ref
        .read(filesControllerProvider(currentFolderId).notifier)
        .removeFiles(files.where((f) => !failedIds.contains(f.id)).map((f) => f.id));
  } else if (success > 0 && currentFolderId == null) {
    // Source dari root → file pindah ke folder lain, hapus dari root.
    ref
        .read(filesControllerProvider(null).notifier)
        .removeFiles(files.where((f) => !failedIds.contains(f.id)).map((f) => f.id));
  }

  // Hasil ke user.
  if (success == files.length) {
    final folderLabel = target.folderId == null
        ? l10n.filesMoveTargetRoot
        : (folders
                .where((f) => f.id == target.folderId)
                .map((f) => f.name)
                .firstOrNull ??
            '');
    showAppSnackBar(
      context,
      l10n.filesMoveSuccess(success, folderLabel),
      variant: AppSnackBarVariant.success,
    );
    if (renamedCount > 0) {
      // unused — backend saat ini tidak return informasi rename di mobile
      // flow ini. Bisa ditambah kemudian.
    }
  } else if (success > 0) {
    showAppSnackBar(
      context,
      l10n.filesMovePartial(success, files.length),
      variant: AppSnackBarVariant.info,
    );
  } else {
    showAppSnackBar(context, l10n.filesMoveAllFailed,
        variant: AppSnackBarVariant.error);
  }
}

/// Value returned by the sheet to its caller.
class MoveTarget {
  const MoveTarget(this.folderId);
  final String? folderId; // null = root
}

class _MoveSheet extends ConsumerStatefulWidget {
  const _MoveSheet({
    required this.folders,
    required this.currentFolderId,
    required this.filesCount,
    this.fileLabel,
  });
  final List<Folder> folders;
  final String? currentFolderId;
  final int filesCount;
  final String? fileLabel;

  @override
  ConsumerState<_MoveSheet> createState() => _MoveSheetState();
}

class _MoveSheetState extends ConsumerState<_MoveSheet> {
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final scheme = Theme.of(context).colorScheme;

    // Filter folder by query + exclude descendants of current folder.
    final descendantIds = _collectDescendantIds(widget.folders, widget.currentFolderId);
    final excluded = <String>{
      if (widget.currentFolderId != null) ...descendantIds,
      if (widget.currentFolderId != null) widget.currentFolderId!,
    };
    final filtered = widget.folders
        .where((f) => !excluded.contains(f.id))
        .where((f) => _query.isEmpty || f.name.toLowerCase().contains(_query.toLowerCase()))
        .toList()
      ..sort((a, b) {
        return a.name.toLowerCase().compareTo(b.name.toLowerCase());
      });

    return SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          // handle bar
          Center(
            child: Container(
              width: 40,
              height: 4,
              margin: const EdgeInsets.only(top: 12),
              decoration: BoxDecoration(
                color: scheme.onSurface.withValues(alpha: 0.20),
                borderRadius: AppRadii.pillBorder,
              ),
            ),
          ),
          const SizedBox(height: 16),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(
                widget.filesCount == 1
                    ? l10n.filesMoveTitleSingle
                    : l10n.filesMoveTitleBulk(widget.filesCount),
                style: AppTypography.headlineLgMobile,
              ),
            ),
          ),
          if (widget.fileLabel != null) ...[
            const SizedBox(height: 4),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 20),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text(
                  widget.fileLabel!,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodyMd.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ),
            ),
          ],
          const SizedBox(height: 12),
          // Search field — handy ketika user punya banyak folder.
          Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
            child: TextField(
              autofocus: false,
              decoration: InputDecoration(
                hintText: l10n.filesMoveSearchHint,
                prefixIcon: const Icon(Icons.search, size: 20),
                isDense: true,
                contentPadding: const EdgeInsets.symmetric(vertical: 12),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(12),
                  borderSide: BorderSide.none,
                ),
                filled: true,
                fillColor: scheme.surfaceContainerHigh,
              ),
              onChanged: (v) => setState(() => _query = v),
            ),
          ),
          Flexible(
            child: ListView(
              shrinkWrap: true,
              padding: const EdgeInsets.only(bottom: 12),
              children: [
                _Tile(
                  icon: Icons.home_outlined,
                  label: l10n.filesMoveTargetRoot,
                  onTap: () => Navigator.of(context).pop(const MoveTarget(null)),
                ),
                if (filtered.isEmpty && _query.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.all(20),
                    child: Text(
                      l10n.filesMoveNoMatch,
                      style: AppTypography.bodyMd.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                    ),
                  )
                else if (filtered.isEmpty)
                  Padding(
                    padding: const EdgeInsets.all(20),
                    child: Text(
                      l10n.filesMoveNoFolders,
                      style: AppTypography.bodyMd.copyWith(
                        color: scheme.onSurfaceVariant,
                      ),
                    ),
                  )
                else
                  ...filtered.map((f) => _Tile(
                        icon: Icons.folder_outlined,
                        label: f.name,
                        onTap: () => Navigator.of(context).pop(MoveTarget(f.id)),
                      )),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _Tile extends StatelessWidget {
  const _Tile({required this.icon, required this.label, required this.onTap});
  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
          child: Row(
            children: [
              Icon(icon, size: 22),
              const SizedBox(width: 16),
              Expanded(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodyLg,
                ),
              ),
              Icon(
                Icons.chevron_right,
                size: 20,
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Kumpulkan id semua descendant dari [ancestorId]. Digunakan untuk disable
/// pick folder tujuan yang ada di dalam subtree source.
Set<String> _collectDescendantIds(List<Folder> all, String? ancestorId) {
  if (ancestorId == null) return const {};
  final byParent = <String, List<String>>{};
  for (final f in all) {
    if (f.parentId != null) {
      (byParent[f.parentId!] ??= []).add(f.id);
    }
  }
  final result = <String>{};
  final stack = <String>[ancestorId];
  while (stack.isNotEmpty) {
    final cur = stack.removeLast();
    final kids = byParent[cur] ?? const <String>[];
    for (final k in kids) {
      if (result.add(k)) stack.add(k);
    }
  }
  return result;
}