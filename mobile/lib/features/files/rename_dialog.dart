import 'package:flutter/material.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../theme/colors.dart';
import '../../theme/radii.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';
import '../../widgets/etheric_button.dart';
import '../../widgets/etheric_text_field.dart';

/// Simple text-input dialog used to rename a file or folder.
/// Returns the new name (trimmed) or null if cancelled.
class RenameDialog extends StatefulWidget {
  const RenameDialog({super.key, required this.currentName});

  final String currentName;

  @override
  State<RenameDialog> createState() => _RenameDialogState();
}

class _RenameDialogState extends State<RenameDialog> {
  late final TextEditingController _ctrl =
      TextEditingController(text: widget.currentName);

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    return Dialog(
      backgroundColor: AppColors.surface,
      shape: RoundedRectangleBorder(borderRadius: AppRadii.cardBorder),
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.innerPadding),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              l10n.filesRenameTitle,
              style: AppTypography.headlineLgMobile,
            ),
            const SizedBox(height: 16),
            EthericTextField(
              controller: _ctrl,
              autofocus: true,
              hint: widget.currentName,
            ),
            const SizedBox(height: 20),
            Row(
              children: [
                Expanded(
                  child: EthericButton(
                    label: l10n.commonCancel,
                    variant: EthericButtonVariant.secondary,
                    onPressed: () => Navigator.of(context).pop(),
                    expanded: true,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: EthericButton(
                    label: l10n.filesRenameSave,
                    onPressed: () =>
                        Navigator.of(context).pop(_ctrl.text.trim()),
                    expanded: true,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
