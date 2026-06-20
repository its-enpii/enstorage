import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';

import '../../data/repositories/files_repository.dart';
import '../../l10n/gen/app_localizations.dart';
import '../../state/upload_state.dart';
import '../../theme/colors.dart';
import '../../theme/radii.dart';
import '../../widgets/list_menu_sheet.dart';
import '../../widgets/nav_aware_sheet.dart';

/// Which camera mode the user picked in the sub-sheet.
enum CameraMode { photo, video }

/// Returns the chosen [CameraMode], or null if dismissed.
Future<CameraMode?> showCameraModeSheet(BuildContext context) {
  final l10n = AppLocalizations.of(context)!;
  return showModalBottomSheet<CameraMode>(
    context: context,
    backgroundColor: Theme.of(context).colorScheme.surface,
    shape: const RoundedRectangleBorder(borderRadius: AppRadii.topSheetBorder),
    isScrollControlled: true,
    useRootNavigator: true,
    builder: (ctx) {
      return NavAwareSheet(
        child: ListMenuSheet(
          title: l10n.cameraModeTitle,
          children: [
            ListMenuTile(
              icon: Icons.photo_camera_outlined,
              iconFg: AppColors.onSurface,
              iconBg: AppColors.surfaceHigh,
              label: l10n.cameraModePhoto,
              onTap: () => Navigator.of(ctx).pop(CameraMode.photo),
            ),
            ListMenuTile(
              icon: Icons.videocam_outlined,
              iconFg: AppColors.onSurface,
              iconBg: AppColors.surfaceHigh,
              label: l10n.cameraModeVideo,
              onTap: () => Navigator.of(ctx).pop(CameraMode.video),
            ),
          ],
        ),
      );
    },
  );
}

/// Show the camera-mode sub-sheet, then open the camera in that mode and
/// upload the result. Pass [folderId] for files inside a folder; null = root.
Future<void> runCameraCapture(BuildContext context, {String? folderId}) async {
  final mode = await showCameraModeSheet(context);
  if (mode == null) return;

  final picker = ImagePicker();
  XFile? shot;
  try {
    shot = switch (mode) {
      CameraMode.photo => await picker.pickImage(source: ImageSource.camera),
      CameraMode.video => await picker.pickVideo(source: ImageSource.camera),
    };
  } catch (_) {
    return;
  }
  if (shot == null) return;
  if (!context.mounted) return;

  final container = ProviderScope.containerOf(context, listen: false);
  final repo = container.read(filesRepositoryProvider);
  final ctrl = container.read(uploadControllerProvider.notifier);
  final id = ctrl.start(shot.name);
  try {
    await repo.uploadFile(
      path: shot.path,
      filename: shot.name,
      folderId: folderId,
      onProgress: (s, t) => ctrl.update(id, sent: s, total: t),
    );
    ctrl.complete(id);
  } catch (_) {
    ctrl.fail(id);
  }
}
