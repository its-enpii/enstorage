import 'package:flutter/material.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../theme/radii.dart';
import '../../theme/typography.dart';
import '../../widgets/app_dialog.dart';
import '../../widgets/etheric_button.dart';
import '../../widgets/etheric_text_field.dart';
import '../../widgets/nav_aware_sheet.dart';

/// Returns the entered name, or null if cancelled.
Future<String?> showCreateFolderDialog(BuildContext context) {
  final controller = TextEditingController();
  return showAppBottomSheet<String>(
    context: context,
    backgroundColor: Theme.of(context).colorScheme.surface,
    builder: (ctx) {
      final l10n = AppLocalizations.of(ctx)!;
      return NavAwareSheet(
        child: Padding(
          padding: EdgeInsets.only(
            left: 20,
            right: 20,
            top: 20,
            // Keyboard inset (when open) — NavAwareSheet handles the
            // bottom-nav clearance via its max height constraint.
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Center(
                child: Container(
                  width: 40,
                  height: 4,
                  margin: const EdgeInsets.only(bottom: 16),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: 0.20),
                    borderRadius: AppRadii.pillBorder,
                  ),
                ),
              ),
              Text(l10n.fabNewFolder, style: AppTypography.headlineLgMobile),
              const SizedBox(height: 8),
              Text(
                l10n.fabNewFolderDesc,
                style: AppTypography.bodyMd,
              ),
              const SizedBox(height: 16),
              EthericTextField(
                controller: controller,
                hint: l10n.fabNewFolderPlaceholder,
                autofocus: true,
                textInputAction: TextInputAction.done,
                onSubmitted: (v) => Navigator.of(ctx).pop(v.trim()),
              ),
              const SizedBox(height: 16),
              Row(
                children: [
                  Expanded(
                    child: EthericButton(
                      label: l10n.fabCancel,
                      variant: EthericButtonVariant.secondary,
                      onPressed: () => Navigator.of(ctx).pop(),
                      expanded: true,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: EthericButton(
                      label: l10n.fabCreateFolder,
                      onPressed: () =>
                          Navigator.of(ctx).pop(controller.text.trim()),
                      expanded: true,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      );
    },
  );
}
