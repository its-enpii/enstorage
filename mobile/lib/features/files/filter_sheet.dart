import 'package:flutter/material.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../state/files_state.dart';
import '../../theme/colors.dart';
import '../../theme/radii.dart';
import '../../theme/typography.dart';
import '../../widgets/list_menu_sheet.dart';
import '../../widgets/nav_aware_sheet.dart';

enum FileListScope { all, folders, files }

extension FileListScopeWire on FileListScope {
  String get param {
    switch (this) {
      case FileListScope.all:
        return 'all';
      case FileListScope.folders:
        return 'folders';
      case FileListScope.files:
        return 'files';
    }
  }
}

class FilterResult {
  const FilterResult({
    this.starredOnly,
    this.type,
    this.scope,
  });
  final bool? starredOnly;
  final FileTypeFilter? type;
  final FileListScope? scope;
}

/// Bottom sheet for the file list filter. Mirrors the web Filter
/// drawer: Starred toggle, Tampilkan (Semua/Folder/File), Tipe
/// (Semua/Gambar/PDF/Dokumen). Reset + Apply buttons at the bottom.
Future<FilterResult?> showFilterSheet(
    BuildContext context, FilesFilter current, FileListScope currentScope) {
  bool starredOnly = current.starredOnly;
  FileTypeFilter type = current.type;
  FileListScope scope = currentScope;
  return showModalBottomSheet<FilterResult>(
    context: context,
    backgroundColor: Theme.of(context).colorScheme.surface,
    shape: const RoundedRectangleBorder(borderRadius: AppRadii.topSheetBorder),
    isScrollControlled: true,
    useRootNavigator: true,
    builder: (ctx) {
      final l10n = AppLocalizations.of(ctx)!;
      return NavAwareSheet(
        child: StatefulBuilder(
          builder: (ctx, setState) {
            return ListMenuSheet(
              title: l10n.filterSheetTitle,
              scrollable: true,
              children: [
                // Starred toggle (top of sheet, matches web order).
                // Only the trailing Switch handles toggle — no row
                // onTap, to avoid double-toggling via event propagation.
                ListMenuTile(
                  icon: Icons.star_rounded,
                  iconFg: AppColors.secondary,
                  iconBg: AppColors.secondary.withValues(alpha: 0.10),
                  label: l10n.filterStarredOnly,
                  selected: starredOnly,
                  trailing: Switch.adaptive(
                    value: starredOnly,
                    onChanged: (v) => setState(() => starredOnly = v),
                  ),
                ),
                const Divider(),
                // Tampilkan section.
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
                  child: Text(
                    l10n.filterShowLabel,
                    style: AppTypography.labelSm.copyWith(
                      color: AppColors.onSurfaceVariant,
                    ),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 20),
                  child: Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      _Chip(
                        label: l10n.filterShowAll,
                        selected: scope == FileListScope.all,
                        onTap: () =>
                            setState(() => scope = FileListScope.all),
                      ),
                      _Chip(
                        label: l10n.filterShowFolders,
                        selected: scope == FileListScope.folders,
                        onTap: () =>
                            setState(() => scope = FileListScope.folders),
                      ),
                      _Chip(
                        label: l10n.filterShowFiles,
                        selected: scope == FileListScope.files,
                        onTap: () =>
                            setState(() => scope = FileListScope.files),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                // Tipe section.
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                  child: Text(
                    l10n.filterTypeLabel,
                    style: AppTypography.labelSm.copyWith(
                      color: AppColors.onSurfaceVariant,
                    ),
                  ),
                ),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 20),
                  child: Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      _Chip(
                        label: l10n.filterTypeImage,
                        selected: type == FileTypeFilter.image,
                        onTap: () => setState(
                            () => type = FileTypeFilter.image),
                      ),
                      _Chip(
                        label: l10n.filterTypePdf,
                        selected: type == FileTypeFilter.pdf,
                        onTap: () =>
                            setState(() => type = FileTypeFilter.pdf),
                      ),
                      _Chip(
                        label: l10n.filterTypeDoc,
                        selected: type == FileTypeFilter.doc,
                        onTap: () =>
                            setState(() => type = FileTypeFilter.doc),
                      ),
                      _Chip(
                        label: l10n.filterStatusAll,
                        selected: type == FileTypeFilter.all,
                        onTap: () => setState(
                            () => type = FileTypeFilter.all),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),
                // Reset + Apply buttons.
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
                  child: Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => Navigator.of(ctx).pop(
                            const FilterResult(
                              starredOnly: false,
                              type: FileTypeFilter.all,
                              scope: FileListScope.all,
                            ),
                          ),
                          child: Text(l10n.filterReset),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: FilledButton(
                          onPressed: () => Navigator.of(ctx).pop(
                            FilterResult(
                              starredOnly: starredOnly,
                              type: type,
                              scope: scope,
                            ),
                          ),
                          child: Text(l10n.filterApply),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            );
          },
        ),
      );
    },
  );
}

class _Chip extends StatelessWidget {
  const _Chip({
    required this.label,
    required this.selected,
    required this.onTap,
  });
  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: selected
          ? AppColors.primaryContainer
          : AppColors.surfaceHigh,
      borderRadius: BorderRadius.circular(999),
      child: InkWell(
        borderRadius: BorderRadius.circular(999),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          child: Text(
            label,
            style: AppTypography.bodySm.copyWith(
              color: selected
                  ? AppColors.onPrimaryContainer
                  : AppColors.onSurface,
              fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            ),
          ),
        ),
      ),
    );
  }
}
