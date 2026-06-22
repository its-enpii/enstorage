import 'package:flutter/material.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../state/files_state.dart';
import '../../theme/radii.dart';
import '../../widgets/list_menu_sheet.dart';
import '../../widgets/nav_aware_sheet.dart';

class SortResult {
  const SortResult({required this.sort, required this.ascending});
  final FileSort sort;
  final bool ascending;
}

/// Bottom sheet for picking the active sort. Returns null on dismiss.
Future<SortResult?> showSortSheet(BuildContext context, FilesFilter current) {
  FileSort sort = current.sort;
  bool ascending = current.ascending;
  return showModalBottomSheet<SortResult>(
    context: context,
    backgroundColor: Theme.of(context).colorScheme.surface,
    shape: const RoundedRectangleBorder(borderRadius: AppRadii.topSheetBorder),
    isScrollControlled: true,
    useRootNavigator: true,
    builder: (ctx) {
      final l10n = AppLocalizations.of(ctx)!;
      final scheme = Theme.of(ctx).colorScheme;
      return NavAwareSheet(
        child: StatefulBuilder(
          builder: (ctx, setState) {
            Widget sortTile(
              IconData icon,
              String label,
              FileSort value,
            ) {
              final selected = sort == value;
              return ListMenuTile(
                icon: icon,
                iconFg: scheme.onSurface,
                iconBg: selected
                    ? scheme.primary.withValues(alpha: 0.10)
                    : scheme.surfaceContainerHigh,
                label: label,
                selected: selected,
                onTap: () => setState(() {
                  if (sort == value) {
                    ascending = !ascending;
                  } else {
                    sort = value;
                    ascending = true;
                  }
                }),
                trailing: selected
                    ? AnimatedRotation(
                        turns: ascending ? 0 : 0.5,
                        duration: const Duration(milliseconds: 200),
                        curve: Curves.easeInOut,
                        child: Icon(
                          Icons.arrow_upward,
                          color: scheme.primary,
                          size: 20,
                        ),
                      )
                    : null,
              );
            }

            return ListMenuSheet(
              title: l10n.sortSheetTitle,
              children: [
                sortTile(Icons.sort_by_alpha, l10n.sortByName, FileSort.name),
                sortTile(Icons.calendar_today_outlined, l10n.sortByCreatedAt,
                    FileSort.createdAt),
                sortTile(Icons.cloud_upload_outlined, l10n.sortByUploadedAt,
                    FileSort.uploadedAt),
                sortTile(Icons.straighten_outlined, l10n.sortBySize,
                    FileSort.size),
                const SizedBox(height: 16),
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
                  child: SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: () => Navigator.of(ctx)
                          .pop(SortResult(sort: sort, ascending: ascending)),
                      child: Text(l10n.commonConfirm),
                    ),
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