import 'package:flutter/material.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../theme/typography.dart';
import '../../widgets/app_dialog.dart';
import '../../widgets/etheric_button.dart';
import '../../widgets/etheric_text_field.dart';

/// Simple text-input dialog used to rename a file or folder.
/// Returns the new name (trimmed) or null if cancelled.
///
/// Returned from [showAppDialog] — do not wrap in another `Dialog`,
/// the outer shell is provided there.
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
    return AppDialogBody(
      title: Text(l10n.filesRenameTitle, style: AppTypography.headlineLgMobile),
      actions: [
        EthericButton(
          label: l10n.commonCancel,
          variant: EthericButtonVariant.secondary,
          onPressed: () => Navigator.of(context).pop(),
        ),
        EthericButton(
          label: l10n.filesRenameSave,
          onPressed: () => Navigator.of(context).pop(_ctrl.text.trim()),
        ),
      ],
      children: [
        EthericTextField(
          controller: _ctrl,
          autofocus: true,
          hint: widget.currentName,
        ),
      ],
    );
  }
}