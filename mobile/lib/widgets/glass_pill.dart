import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../theme/colors.dart';
import '../theme/radii.dart';

/// Floating pill toolbar — heavy backdrop blur, surface tint at 80% alpha.
/// Used for bottom nav AND upload progress toast.
class GlassPill extends StatelessWidget {
  const GlassPill({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
    this.width,
    this.color = AppColors.glassSurface,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final double? width;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: AppRadii.pillBorder,
      child: BackdropFilter(
        filter: ui.ImageFilter.blur(sigmaX: 20, sigmaY: 20),
        child: Container(
          width: width,
          padding: padding,
          decoration: BoxDecoration(
            color: color,
            borderRadius: AppRadii.pillBorder,
            border: Border.all(color: Colors.white.withValues(alpha: 0.05)),
            boxShadow: const [
              BoxShadow(
                color: Color(0x66000000),
                blurRadius: 40,
                offset: Offset(0, 20),
              ),
            ],
          ),
          child: child,
        ),
      ),
    );
  }
}
