import 'package:flutter/material.dart';

import '../theme/breakpoints.dart';
import '../theme/radii.dart';

/// Adaptive max widths for app dialogs.
///
///   compact   → 92% of screen width, clamped to [280, 360]
///   medium    → 480
///   expanded  → 560
///
/// The compact value keeps confirmation dialogs comfortably readable on
/// phones; medium and expanded keep dialogs from sprawling on tablet
/// while still leaving generous tap targets.
class DialogMetrics {
  const DialogMetrics._();

  static double maxWidth(BuildContext context) {
    final screenWidth = MediaQuery.sizeOf(context).width;
    if (Breakpoints.isExpanded(context)) return 560;
    if (Breakpoints.isMedium(context)) return 480;
    final pct = screenWidth * 0.92;
    if (pct < 280) return 280;
    if (pct > 360) return 360;
    return pct;
  }

  /// Max width for bottom sheets. Slightly wider than dialogs because
  /// sheets usually host lists / controls rather than text.
  static double sheetMaxWidth(BuildContext context) {
    final screenWidth = MediaQuery.sizeOf(context).width;
    if (Breakpoints.isExpanded(context)) return 480;
    if (Breakpoints.isMedium(context)) return 440;
    if (screenWidth > 600) return 480;
    return screenWidth; // phone = full width
  }
}

/// Show an adaptive dialog.
///
/// Wraps [showDialog] and constrains the dialog's max width via
/// [DialogMetrics.maxWidth]. The dialog's content is wrapped in a
/// scroll view so it can't overflow the viewport on small phones.
Future<T?> showAppDialog<T>({
  required BuildContext context,
  required WidgetBuilder builder,
  bool barrierDismissible = true,
  String? barrierLabel,
  bool useRootNavigator = true,
}) {
  final maxWidth = DialogMetrics.maxWidth(context);
  return showDialog<T>(
    context: context,
    barrierDismissible: barrierDismissible,
    barrierLabel: barrierLabel ??
        MaterialLocalizations.of(context).modalBarrierDismissLabel,
    useRootNavigator: useRootNavigator,
    builder: (ctx) {
      return Dialog(
        backgroundColor: Theme.of(ctx).colorScheme.surfaceContainer,
        surfaceTintColor: Colors.transparent,
        shape: const RoundedRectangleBorder(borderRadius: AppRadii.cardBorder),
        insetPadding: EdgeInsets.symmetric(
          horizontal: (MediaQuery.sizeOf(ctx).width - maxWidth) / 2,
          vertical: 24,
        ),
        clipBehavior: Clip.antiAlias,
        child: ConstrainedBox(
          constraints: BoxConstraints(maxWidth: maxWidth),
          child: Builder(builder: builder),
        ),
      );
    },
  );
}

/// Show an adaptive bottom sheet.
///
/// On compact widths the sheet behaves as a Material bottom sheet
/// (rounded top, full-bleed). On medium/expanded widths the sheet is
/// horizontally centered with a max-width cap so it doesn't span the
/// full tablet screen.
Future<T?> showAppBottomSheet<T>({
  required BuildContext context,
  required WidgetBuilder builder,
  bool isScrollControlled = true,
  bool useSafeArea = false,
  bool useRootNavigator = true,
  Color? backgroundColor,
}) {
  final scheme = Theme.of(context).colorScheme;
  return showModalBottomSheet<T>(
    context: context,
    isScrollControlled: isScrollControlled,
    useSafeArea: useSafeArea,
    useRootNavigator: useRootNavigator,
    backgroundColor: backgroundColor ?? scheme.surfaceContainer,
    shape: const RoundedRectangleBorder(borderRadius: AppRadii.topSheetBorder),
    constraints: BoxConstraints(
      maxWidth: DialogMetrics.sheetMaxWidth(context),
    ),
    builder: builder,
  );
}