import 'package:flutter/material.dart';

/// Brand palette "Enpii" — source of truth.
/// See memory `palette-enpii`.
class EnpiiPalette {
  const EnpiiPalette._();

  static const Color primary = Color(0xFF3D348B); // deep purple/indigo
  static const Color secondary = Color(0xFFE6AF2E); // gold/amber
  static const Color black = Color(0xFF111319); // Etheric "Deep Space" (matches web)
  static const Color offWhite = Color(0xFFe2e2eb); // on-surface (matches web)

  // Surface scale — kept from Etheric Cloud for tonal stacking depth.
  // Cards lift via color contrast, not borders.
  static const Color surfaceContainerLowest = Color(0xFF0B0D14);
  static const Color surfaceContainerLow = Color(0xFF191B22);
  static const Color surfaceContainer = Color(0xFF1E2029);
  static const Color surfaceContainerHigh = Color(0xFF2A2C36);
  static const Color surfaceContainerHighest = Color(0xFF363842);

  // Supporting tones (Material 3 dark scheme alignment)
  static const Color onPrimary = offWhite;
  static const Color primaryContainer = primary;
  static const Color onPrimaryContainer = Color(0xFFABA3FF);
  static const Color onSecondary = black;
  static const Color secondaryContainer = Color(0xFFC08E00);
  static const Color onSecondaryContainer = Color(0xFF3E2B00);
  static const Color onSurface = offWhite;
  static const Color onSurfaceVariant = Color(0xFFC8C4D3);
  static const Color outline = Color(0xFF928F9D);
  static const Color outlineVariant = Color(0xFF474551);
  static const Color error = Color(0xFFFFB4AB);
  static const Color onError = Color(0xFF690005);
  static const Color errorContainer = Color(0xFF93000A);
  static const Color onErrorContainer = Color(0xFFFFDAD6);
}

/// Semantic color roles. Map Material 3 ColorScheme → Enpii brand.
class AppColors {
  const AppColors._();

  // Surfaces
  static const Color background = EnpiiPalette.black;
  static const Color surface = EnpiiPalette.surfaceContainer;
  static const Color surfaceLow = EnpiiPalette.surfaceContainerLow;
  static const Color surfaceHigh = EnpiiPalette.surfaceContainerHigh;
  static const Color surfaceHighest = EnpiiPalette.surfaceContainerHighest;

  // Brand
  static const Color primary = EnpiiPalette.primary;
  static const Color onPrimary = EnpiiPalette.onPrimary;
  static const Color primaryContainer = EnpiiPalette.primaryContainer;
  static const Color onPrimaryContainer = EnpiiPalette.onPrimaryContainer;

  static const Color secondary = EnpiiPalette.secondary;
  static const Color onSecondary = EnpiiPalette.onSecondary;
  static const Color secondaryContainer = EnpiiPalette.secondaryContainer;
  static const Color onSecondaryContainer = EnpiiPalette.onSecondaryContainer;

  // Text
  static const Color onSurface = EnpiiPalette.onSurface;
  static const Color onSurfaceVariant = EnpiiPalette.onSurfaceVariant;
  static const Color outline = EnpiiPalette.outline;
  static const Color outlineVariant = EnpiiPalette.outlineVariant;

  // Semantic
  static const Color error = EnpiiPalette.error;
  static const Color onError = EnpiiPalette.onError;
  static const Color errorContainer = EnpiiPalette.errorContainer;
  static const Color onErrorContainer = EnpiiPalette.onErrorContainer;

  // Overlays
  static const Color scrim = Color(0x99000000); // black/60
  static const Color glassSurface = Color(0xCC1A1D27); // rgba(26,29,39,0.8)
  static const Color cardGlow = Color(0x0DFFFFFF); // inner-glow top edge
}
