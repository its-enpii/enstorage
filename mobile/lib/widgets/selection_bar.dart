import 'package:flutter/material.dart';

import '../theme/colors.dart';
import '../theme/radii.dart';
import '../theme/typography.dart';

/// Selection bar that replaces the top app bar in multi-select mode.
/// Mirrors `.design/file_selection_uploading`.
class SelectionBar extends StatelessWidget {
  const SelectionBar({
    super.key,
    required this.count,
    required this.title,
    this.onClose,
    this.actions = const [],
  });

  final int count;
  final String title;
  final VoidCallback? onClose;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    return Container(
      color: AppColors.background,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      child: Row(
        children: [
          IconButton(
            onPressed: onClose,
            icon: const Icon(Icons.close, color: AppColors.primary),
            tooltip: title,
          ),
          const SizedBox(width: 4),
          Expanded(
            child: Text(
              '$count $title',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: AppTypography.headlineLgMobile.copyWith(color: AppColors.primary),
            ),
          ),
          const SizedBox(width: 8),
          Row(children: actions),
        ],
      ),
    );
  }
}

class SelectionAction extends StatelessWidget {
  const SelectionAction({
    super.key,
    required this.icon,
    required this.tooltip,
    required this.onTap,
    this.danger = false,
  });

  final IconData icon;
  final String tooltip;
  final VoidCallback onTap;
  final bool danger;

  @override
  Widget build(BuildContext context) {
    return IconButton(
      onPressed: onTap,
      tooltip: tooltip,
      icon: Icon(
        icon,
        color: danger ? AppColors.error : AppColors.onSurfaceVariant,
      ),
      style: IconButton.styleFrom(
        backgroundColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: AppRadii.pillBorder),
      ),
    );
  }
}
