import 'package:flutter/material.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../theme/radii.dart';
import '../../widgets/list_menu_sheet.dart';
import '../../widgets/nav_aware_sheet.dart';

/// Result of tapping an action in the FAB sheet.
enum CreateAction { newFolder, uploadFile, scanDocument }

/// Bottom sheet that matches `.design/new_action_bottom_sheet` — handle bar,
/// scrim, action rows. Returns null on dismiss.
Future<CreateAction?> showCreateActionSheet(BuildContext context) {
  return showModalBottomSheet<CreateAction>(
    context: context,
    backgroundColor: Theme.of(context).colorScheme.surface,
    shape: const RoundedRectangleBorder(borderRadius: AppRadii.topSheetBorder),
    isScrollControlled: true,
    useRootNavigator: true,
    builder: (ctx) {
      final l10n = AppLocalizations.of(ctx)!;
      final scheme = Theme.of(ctx).colorScheme;
      return NavAwareSheet(
        child: ListMenuSheet(
          title: l10n.fabSheetTitle,
          children: [
            ListMenuTile(
              icon: Icons.create_new_folder_outlined,
              iconFg: scheme.primary,
              iconBg: scheme.primary.withValues(alpha: 0.10),
              label: l10n.fabNewFolder,
              onTap: () => Navigator.of(ctx).pop(CreateAction.newFolder),
            ),
            ListMenuTile(
              icon: Icons.upload_file_outlined,
              iconFg: scheme.secondary,
              iconBg: scheme.secondary.withValues(alpha: 0.10),
              label: l10n.fabUploadFile,
              onTap: () => Navigator.of(ctx).pop(CreateAction.uploadFile),
            ),
            ListMenuTile(
              icon: Icons.photo_camera_outlined,
              iconFg: scheme.onSurface,
              iconBg: scheme.surfaceContainerHigh,
              label: l10n.fabScanDocument,
              onTap: () => Navigator.of(ctx).pop(CreateAction.scanDocument),
            ),
          ],
        ),
      );
    },
  );
}
