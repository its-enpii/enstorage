import 'package:flutter/material.dart';

import '../theme/colors.dart';

/// Material Symbols-style icon used across the app.
/// Wraps Material Icons to keep dependency footprint small.
class FileIconBox extends StatelessWidget {
  const FileIconBox({
    super.key,
    required this.icon,
    this.size = 24,
    this.bg = AppColors.surfaceHigh,
    this.fg = AppColors.primary,
    this.boxSize = 48,
  });

  final IconData icon;
  final double size;
  final Color bg;
  final Color fg;
  final double boxSize;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: boxSize,
      height: boxSize,
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Icon(icon, color: fg, size: size),
    );
  }
}
