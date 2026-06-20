import 'package:flutter/material.dart';

import '../theme/colors.dart';
import '../theme/radii.dart';
import '../theme/typography.dart';

/// Primary (purple filled) & Secondary (ghost w/ subtle white outline) buttons.
enum EthericButtonVariant { primary, secondary, danger }

class EthericButton extends StatelessWidget {
  const EthericButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.variant = EthericButtonVariant.primary,
    this.icon,
    this.loading = false,
    this.expanded = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final EthericButtonVariant variant;
  final IconData? icon;
  final bool loading;
  final bool expanded;

  @override
  Widget build(BuildContext context) {
    final palette = _palette();
    final child = loading
        ? SizedBox(
            width: 18,
            height: 18,
            child: CircularProgressIndicator(
              strokeWidth: 2,
              valueColor: AlwaysStoppedAnimation(palette.fg),
            ),
          )
        : Row(
            mainAxisSize: MainAxisSize.min,
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              if (icon != null) ...[
                Icon(icon, color: palette.fg, size: 18),
                const SizedBox(width: 8),
              ],
              Text(
                label,
                style: AppTypography.labelSm.copyWith(color: palette.fg),
              ),
            ],
          );

    return Material(
      color: palette.bg,
      borderRadius: AppRadii.controlBorder,
      child: InkWell(
        onTap: loading ? null : onPressed,
        borderRadius: AppRadii.controlBorder,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
          decoration: BoxDecoration(
            color: palette.bg,
            borderRadius: AppRadii.controlBorder,
            border: palette.border == null
                ? null
                : Border.all(color: palette.border!, width: 1),
          ),
          child: expanded
              ? SizedBox(width: double.infinity, child: Center(child: child))
              : child,
        ),
      ),
    );
  }

  _ButtonPalette _palette() {
    switch (variant) {
      case EthericButtonVariant.primary:
        return const _ButtonPalette(
          bg: AppColors.primaryContainer,
          fg: AppColors.onPrimaryContainer,
        );
      case EthericButtonVariant.secondary:
        return _ButtonPalette(
          bg: Colors.transparent,
          fg: AppColors.onSurface,
          border: Colors.white.withValues(alpha: 0.10),
        );
      case EthericButtonVariant.danger:
        return const _ButtonPalette(
          bg: AppColors.errorContainer,
          fg: AppColors.onErrorContainer,
        );
    }
  }
}

class _ButtonPalette {
  const _ButtonPalette({required this.bg, required this.fg, this.border});
  final Color bg;
  final Color fg;
  final Color? border;
}
