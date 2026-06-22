import 'package:flutter/material.dart';

/// Material Symbols-style icon used across the app.
/// Wraps Material Icons to keep dependency footprint small.
class FileIconBox extends StatelessWidget {
  const FileIconBox({
    super.key,
    required this.icon,
    this.size = 24,
    this.bg,
    this.fg,
    this.boxSize = 48,
  });

  final IconData icon;
  final double size;
  final Color? bg;
  final Color? fg;
  final double boxSize;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      width: boxSize,
      height: boxSize,
      decoration: BoxDecoration(
        color: bg ?? scheme.surfaceContainerHigh,
        borderRadius: BorderRadius.circular(12),
      ),
      child: Icon(icon, color: fg ?? scheme.primary, size: size),
    );
  }
}