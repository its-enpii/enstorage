import 'package:flutter/material.dart';

import '../theme/breakpoints.dart';
import '../theme/spacing.dart';

/// Centers a child horizontally and caps its width, so on tablet / desktop
/// the content does not stretch edge-to-edge and feel "pulled apart".
///
/// Padding is also adaptive: phones keep the tight mobile-tuned padding,
/// larger screens get more breathing room.
class ResponsiveContainer extends StatelessWidget {
  const ResponsiveContainer({
    super.key,
    required this.child,
    this.compactMaxWidth = double.infinity,
    this.mediumMaxWidth = 720,
    this.expandedMaxWidth = 1080,
    this.useContainerPadding = true,
  });

  final Widget child;
  final double compactMaxWidth;
  final double mediumMaxWidth;
  final double expandedMaxWidth;
  final bool useContainerPadding;

  double _maxWidthFor(BuildContext context) {
    if (Breakpoints.isExpanded(context)) return expandedMaxWidth;
    if (Breakpoints.isMedium(context)) return mediumMaxWidth;
    return compactMaxWidth;
  }

  EdgeInsets _paddingFor(BuildContext context) {
    if (!useContainerPadding) return EdgeInsets.zero;
    if (Breakpoints.isExpanded(context)) {
      return const EdgeInsets.symmetric(horizontal: 48);
    }
    if (Breakpoints.isMedium(context)) {
      return const EdgeInsets.symmetric(horizontal: 32);
    }
    return const EdgeInsets.symmetric(horizontal: AppSpacing.containerPadding);
  }

  @override
  Widget build(BuildContext context) {
    final maxWidth = _maxWidthFor(context);
    return Align(
      alignment: Alignment.topCenter,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: maxWidth),
        child: Padding(
          padding: _paddingFor(context),
          child: child,
        ),
      ),
    );
  }
}