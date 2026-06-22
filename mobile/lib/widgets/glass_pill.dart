import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../theme/radii.dart';

/// Floating pill toolbar — heavy backdrop blur, surface tint at 80% alpha.
/// Used for bottom nav AND upload progress toast.
///
/// Color defaults to a brightness-aware glass: dark uses surface at 80%
/// alpha (matches web `rgba(26, 29, 39, 0.8)`), light uses surface at
/// 85% alpha (matches web `rgba(251, 248, 255, 0.85)`).
///
/// Shadow is drawn on a parent `Material` widget with `clipBehavior:
/// none`, so the rounded-rect clip on the pill itself doesn't cut the
/// drop-shadow off. Without this, Flutter clips the shadow to the
/// pill's bounding box and you can't see it.
class GlassPill extends StatelessWidget {
  const GlassPill({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
    this.width,
    this.color,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final double? width;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final isDark = theme.brightness == Brightness.dark;
    final defaultColor = isDark
        ? scheme.surfaceContainer.withValues(alpha: 0.80)
        : scheme.surface.withValues(alpha: 0.85);

    // Material with clipBehavior: none paints the shadow outside its
    // own rounded bounds. `color: transparent` keeps the pill's own
    // glass color (set inside the Container) untouched.
    return Material(
      type: MaterialType.transparency,
      clipBehavior: Clip.none,
      child: Container(
        width: width,
        decoration: BoxDecoration(
          borderRadius: AppRadii.pillBorder,
          boxShadow: [
            BoxShadow(
              color: isDark
                  ? const Color(0xAA000000) // ~67% — dark mode shadow
                  : const Color(0x33000000), // ~20% — light mode shadow
              blurRadius: isDark ? 32 : 20,
              spreadRadius: 0,
              offset: Offset(0, isDark ? 12 : 6),
            ),
          ],
        ),
        child: ClipRRect(
          borderRadius: AppRadii.pillBorder,
          child: BackdropFilter(
            filter: ui.ImageFilter.blur(sigmaX: 20, sigmaY: 20),
            child: Container(
              padding: padding,
              decoration: BoxDecoration(
                color: color ?? defaultColor,
                borderRadius: AppRadii.pillBorder,
                border: Border.all(color: Colors.white.withValues(alpha: 0.05)),
              ),
              child: child,
            ),
          ),
        ),
      ),
    );
  }
}