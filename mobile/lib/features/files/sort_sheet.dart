import 'package:flutter/material.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../state/files_state.dart';
import '../../theme/colors.dart';
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
      return NavAwareSheet(
        child: StatefulBuilder(
          builder: (ctx, setState) {
            return ListMenuSheet(
              title: l10n.sortSheetTitle,
              children: [
                ListMenuTile(
                  icon: Icons.sort_by_alpha,
                  iconFg: AppColors.onSurface,
                  iconBg: sort == FileSort.name
                      ? AppColors.primary.withValues(alpha: 0.10)
                      : AppColors.surfaceHigh,
                  label: l10n.sortByName,
                  selected: sort == FileSort.name,
                  onTap: () => setState(() {
                    if (sort == FileSort.name) {
                      ascending = !ascending;
                    } else {
                      sort = FileSort.name;
                      ascending = true;
                    }
                  }),
                  trailing: sort == FileSort.name
                      ? AnimatedRotation(
                          turns: ascending ? 0 : 0.5,
                          duration: const Duration(milliseconds: 200),
                          curve: Curves.easeInOut,
                          child: const Icon(
                            Icons.arrow_upward,
                            color: AppColors.primary,
                            size: 20,
                          ),
                        )
                      : null,
                ),
                ListMenuTile(
                  icon: Icons.calendar_today_outlined,
                  iconFg: AppColors.onSurface,
                  iconBg: sort == FileSort.createdAt
                      ? AppColors.primary.withValues(alpha: 0.10)
                      : AppColors.surfaceHigh,
                  label: l10n.sortByCreatedAt,
                  selected: sort == FileSort.createdAt,
                  onTap: () => setState(() {
                    if (sort == FileSort.createdAt) {
                      ascending = !ascending;
                    } else {
                      sort = FileSort.createdAt;
                      ascending = true;
                    }
                  }),
                  trailing: sort == FileSort.createdAt
                      ? AnimatedRotation(
                          turns: ascending ? 0 : 0.5,
                          duration: const Duration(milliseconds: 200),
                          curve: Curves.easeInOut,
                          child: const Icon(
                            Icons.arrow_upward,
                            color: AppColors.primary,
                            size: 20,
                          ),
                        )
                      : null,
                ),
                ListMenuTile(
                  icon: Icons.cloud_upload_outlined,
                  iconFg: AppColors.onSurface,
                  iconBg: sort == FileSort.uploadedAt
                      ? AppColors.primary.withValues(alpha: 0.10)
                      : AppColors.surfaceHigh,
                  label: l10n.sortByUploadedAt,
                  selected: sort == FileSort.uploadedAt,
                  onTap: () => setState(() {
                    if (sort == FileSort.uploadedAt) {
                      ascending = !ascending;
                    } else {
                      sort = FileSort.uploadedAt;
                      ascending = true;
                    }
                  }),
                  trailing: sort == FileSort.uploadedAt
                      ? AnimatedRotation(
                          turns: ascending ? 0 : 0.5,
                          duration: const Duration(milliseconds: 200),
                          curve: Curves.easeInOut,
                          child: const Icon(
                            Icons.arrow_upward,
                            color: AppColors.primary,
                            size: 20,
                          ),
                        )
                      : null,
                ),
                ListMenuTile(
                  icon: Icons.straighten_outlined,
                  iconFg: AppColors.onSurface,
                  iconBg: sort == FileSort.size
                      ? AppColors.primary.withValues(alpha: 0.10)
                      : AppColors.surfaceHigh,
                  label: l10n.sortBySize,
                  selected: sort == FileSort.size,
                  onTap: () => setState(() {
                    if (sort == FileSort.size) {
                      ascending = !ascending;
                    } else {
                      sort = FileSort.size;
                      ascending = true;
                    }
                  }),
                  trailing: sort == FileSort.size
                      ? AnimatedRotation(
                          turns: ascending ? 0 : 0.5,
                          duration: const Duration(milliseconds: 200),
                          curve: Curves.easeInOut,
                          child: const Icon(
                            Icons.arrow_upward,
                            color: AppColors.primary,
                            size: 20,
                          ),
                        )
                      : null,
                ),
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
