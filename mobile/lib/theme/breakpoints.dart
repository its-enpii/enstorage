import 'package:flutter/widgets.dart';

/// Responsive breakpoints, following Material 3 window size classes.
///
///   compact   <  600  → phone (portrait)
///   medium    600–839 → small tablet / phone landscape / foldable
///   expanded  ≥  840  → large tablet / desktop
class Breakpoints {
  const Breakpoints._();

  static const double compact = 600;
  static const double medium = 840;

  static bool isCompact(BuildContext context) =>
      MediaQuery.sizeOf(context).width < compact;

  static bool isMedium(BuildContext context) {
    final w = MediaQuery.sizeOf(context).width;
    return w >= compact && w < medium;
  }

  static bool isExpanded(BuildContext context) =>
      MediaQuery.sizeOf(context).width >= medium;

  /// True on any form factor larger than a phone.
  static bool isTabletLike(BuildContext context) => isExpanded(context);

  /// True when there is enough room for a side-by-side list+detail layout.
  static bool supportsTwoPane(BuildContext context) => isExpanded(context);

  /// Adaptive grid cross-axis count for card grids (files, starred, etc.).
  ///
  ///   compact  → 2  (phone portrait)
  ///   medium   → 3  (small tablet / phone landscape)
  ///   expanded → 5  (large tablet)
  ///
  /// The caller may pass a custom value for each class if a particular
  /// grid wants a different density.
  static int gridCount(
    BuildContext context, {
    int compact = 2,
    int medium = 3,
    int expanded = 5,
  }) {
    if (isExpanded(context)) return expanded;
    if (isMedium(context)) return medium;
    return compact;
  }
}