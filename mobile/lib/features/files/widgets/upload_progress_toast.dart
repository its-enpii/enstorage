import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../l10n/gen/app_localizations.dart';
import '../../../state/upload_state.dart';
import '../../../theme/colors.dart';
import '../../../theme/typography.dart';
import '../../../widgets/glass_pill.dart';

/// Floating glass pill at bottom-24 showing active uploads.
/// Mirrors `.design/file_selection_uploading` upload toast.
class UploadProgressToast extends ConsumerWidget {
  const UploadProgressToast({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final uploads = ref.watch(uploadControllerProvider);
    if (uploads.isEmpty) return const SizedBox.shrink();
    final l10n = AppLocalizations.of(context)!;
    final u = uploads.first;
    return Positioned(
      left: 0,
      right: 0,
      bottom: 96,
      child: Center(
        child: GlassPill(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                width: 24,
                height: 24,
                child: CircularProgressIndicator(
                  value: u.total == 0 ? null : u.sent / u.total,
                  strokeWidth: 2,
                  color: AppColors.primary,
                  backgroundColor: AppColors.surfaceHigh,
                ),
              ),
              const SizedBox(width: 12),
              ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 180),
                child: Text(
                  u.filename,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: AppTypography.bodyMd.copyWith(color: AppColors.onSurface),
                ),
              ),
              const SizedBox(width: 12),
              Text(
                '${u.percent}%',
                style: AppTypography.labelSm.copyWith(color: AppColors.primary),
              ),
              const SizedBox(width: 8),
              IconButton(
                onPressed: () =>
                    ref.read(uploadControllerProvider.notifier).complete(u.id),
                tooltip: l10n.uploadCancel,
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.close,
                    size: 18, color: AppColors.onSurfaceVariant),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
