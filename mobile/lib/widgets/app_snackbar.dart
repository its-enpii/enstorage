import 'package:flutter/material.dart';

import '../theme/typography.dart';

/// Tone variants for [showAppSnackBar]. Each picks its own icon and
/// background color from the current [ColorScheme] at call time, so the
/// look matches the active theme (dark or light).
enum AppSnackBarVariant { success, error, info }

/// Show a branded snackbar with an icon + tone-specific color. This
/// replaces the bare `ScaffoldMessenger.of(context).showSnackBar(
/// SnackBar(content: Text(...)))` pattern that was used in 28 places
/// before this helper existed.
///
/// Variants:
/// - [AppSnackBarVariant.success] — check icon, surface-container-high bg.
/// - [AppSnackBarVariant.error]   — error icon, errorContainer bg.
/// - [AppSnackBarVariant.info]    — info icon, surface-container-high bg.
///
/// Default duration is 2.5s (4s for errors). Callers can override.
void showAppSnackBar(
  BuildContext context,
  String message, {
  AppSnackBarVariant variant = AppSnackBarVariant.info,
  Duration? duration,
}) {
  final scheme = Theme.of(context).colorScheme;
  final (Color bg, Color fg, IconData icon) = switch (variant) {
    AppSnackBarVariant.success => (
        scheme.surfaceContainerHigh,
        scheme.onSurface,
        Icons.check_circle_outline,
      ),
    AppSnackBarVariant.error => (
        scheme.errorContainer,
        scheme.onErrorContainer,
        Icons.error_outline,
      ),
    AppSnackBarVariant.info => (
        scheme.surfaceContainerHigh,
        scheme.onSurface,
        Icons.info_outline,
      ),
  };

  final messenger = ScaffoldMessenger.of(context);
  messenger
    ..clearSnackBars()
    ..showSnackBar(
      SnackBar(
        backgroundColor: bg,
        duration: duration ??
            (variant == AppSnackBarVariant.error
                ? const Duration(seconds: 4)
                : const Duration(milliseconds: 2500)),
        content: Row(
          children: [
            Icon(icon, color: fg, size: 20),
            const SizedBox(width: 12),
            Expanded(
              child: Text(
                message,
                style: AppTypography.bodyMd.copyWith(color: fg),
              ),
            ),
          ],
        ),
      ),
    );
}