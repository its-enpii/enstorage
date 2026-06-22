import 'package:flutter/material.dart';

import '../theme/radii.dart';
import '../theme/shadows.dart';

/// Premium vault card — surface bg, no border, inner-glow, soft shadow,
/// optional hover/press lift to 1.02x.
class EthericCard extends StatefulWidget {
  const EthericCard({
    super.key,
    required this.child,
    this.onTap,
    this.onLongPress,
    this.padding = const EdgeInsets.all(10),
    this.selected = false,
    this.badge,
    this.radius = AppRadii.card,
    this.height,
    this.color,
    this.borderColor,
  });

  final Widget child;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;
  final EdgeInsetsGeometry padding;
  final bool selected;
  final Widget? badge;
  final double radius;

  /// Override warna background card (default = surfaceContainer).
  final Color? color;

  /// Border accent (mis. untuk highlight starred files).
  final Color? borderColor;

  /// Optional fixed card height. When set, the card is exactly this
  /// tall regardless of its content height — useful for matching
  /// card heights across heterogeneous content (folder vs file).
  final double? height;

  @override
  State<EthericCard> createState() => _EthericCardState();
}

class _EthericCardState extends State<EthericCard> {
  bool _pressed = false;

  @override
  Widget build(BuildContext context) {
    final scale = _pressed ? 0.98 : 1.0;
    final borderRadius = BorderRadius.circular(widget.radius);
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final primary = scheme.primary;
    Widget card = AnimatedScale(
      scale: scale,
      duration: const Duration(milliseconds: 150),
      curve: Curves.easeOut,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 200),
        decoration: BoxDecoration(
          color: widget.color ?? scheme.surfaceContainer,
          borderRadius: borderRadius,
          border: widget.borderColor != null
              ? Border.all(color: widget.borderColor!, width: 1.5)
              : null,
          boxShadow: [
            ...AppShadows.innerGlow(theme.brightness),
            ...AppShadows.ambient(theme.brightness),
            if (widget.selected) ...AppShadows.primaryGlow(theme.brightness),
            // Outset ring drawn as a shadow so it doesn't claim any of
            // the card's interior width — content keeps the same size
            // whether the card is selected or not.
            if (widget.selected)
              BoxShadow(
                color: primary,
                blurRadius: 0,
                spreadRadius: 2,
              ),
          ],
        ),
        transform: Matrix4.identity(),
        child: Material(
          color: Colors.transparent,
          borderRadius: borderRadius,
          child: InkWell(
            onTap: widget.onTap,
            onLongPress: widget.onLongPress,
            borderRadius: borderRadius,
            splashColor: primary.withValues(alpha: 0.08),
            highlightColor: primary.withValues(alpha: 0.04),
            onTapDown: widget.onTap != null
                ? (_) => setState(() => _pressed = true)
                : null,
            onTapUp: widget.onTap != null
                ? (_) => setState(() => _pressed = false)
                : null,
            onTapCancel: () => setState(() => _pressed = false),
            child: Stack(
              children: [
                Padding(padding: widget.padding, child: widget.child),
                if (widget.badge != null) widget.badge!,
              ],
            ),
          ),
        ),
      ),
    );
    if (widget.height != null) {
      card = SizedBox(height: widget.height, child: card);
    }
    return card;
  }
}
