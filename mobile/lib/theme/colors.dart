import 'package:flutter/material.dart';

/// Brand palette "Enpii" — source of truth.
/// See memory `palette-enpii`. Values mirror the web app's
/// `web/src/app/globals.css` so the mobile and web skins stay in sync.
class EnpiiPalette {
  const EnpiiPalette._();

  // Brand seed (matches web --color-primary-container).
  static const Color primary = Color(0xFF3D348B); // deep purple/indigo
  static const Color secondary = Color(0xFFE6AF2E); // gold/amber
  static const Color black = Color(0xFF111319); // Etheric "Deep Space" (matches web --color-background)
  static const Color offWhite = Color(0xFFE2E2EB); // on-surface (matches web --color-on-surface)

  // ---- Dark surface scale (matches web @theme block) -----------------
  static const Color surfaceContainerLowest = Color(0xFF0C0E14);
  static const Color surfaceContainerLow = Color(0xFF191B22);
  static const Color surfaceContainer = Color(0xFF1E1F26);
  static const Color surfaceContainerHigh = Color(0xFF282A30);
  static const Color surfaceContainerHighest = Color(0xFF33343B);
  // Web's --color-surface is #1A1D27 (the canonical "card" color, sits
  // between Container and ContainerLow in luminance).
  static const Color surface = Color(0xFF1A1D27);

  // Dark primary/secondary/error — match web exactly.
  // Web splits "primary" (the light tint that paints on dark surfaces)
  // from "primary-container" (the deep brand color). Mobile follows.
  static const Color onPrimary = Color(0xFF2C2179);
  static const Color primaryContainer = Color(0xFF3D348B);
  static const Color onPrimaryContainer = Color(0xFFABA3FF);
  static const Color surfaceTint = Color(0xFFC6C0FF);
  static const Color primaryTint = Color(0xFFC6C0FF);

  static const Color onSecondary = Color(0xFF1A1300);
  static const Color secondaryContainer = Color(0xFF7A5C14);
  static const Color onSecondaryContainer = Color(0xFFFDE9B8);

  static const Color onSurface = offWhite;
  static const Color onSurfaceVariant = Color(0xFFC8C4D3);
  static const Color outline = Color(0xFF928F9D);
  static const Color outlineVariant = Color(0xFF474551);

  static const Color error = Color(0xFFFFB4AB);
  static const Color onError = Color(0xFF690005);
  static const Color errorContainer = Color(0xFF93000A);
  static const Color onErrorContainer = Color(0xFFFFDAD6);

  // ---- Light surface scale (matches web :root:not(.dark) block) ------
  static const Color lightBackground = Color(0xFFF4F4FA);
  static const Color lightOnBackground = Color(0xFF1A1B21);
  static const Color lightSurfaceContainerLowest = Color(0xFFFFFFFF);
  static const Color lightSurfaceContainerLow = Color(0xFFF0F0F6);
  static const Color lightSurfaceContainer = Color(0xFFEAEBF0);
  static const Color lightSurfaceContainerHigh = Color(0xFFE4E5EA);
  static const Color lightSurfaceContainerHighest = Color(0xFFDEDFE5);
  // Web's --color-surface in light is #FBF8FF — the canonical "card" color.
  static const Color lightSurface = Color(0xFFFBF8FF);

  static const Color lightOnSurface = Color(0xFF1A1B21);
  static const Color lightOnSurfaceVariant = Color(0xFF45464F);
  static const Color lightOutline = Color(0xFF757680);
  static const Color lightOutlineVariant = Color(0xFFC5C5D0);

  static const Color lightPrimary = Color(0xFF5B53AA);
  static const Color lightOnPrimary = Color(0xFFFFFFFF);
  static const Color lightPrimaryContainer = Color(0xFFE4DFFF);
  static const Color lightOnPrimaryContainer = Color(0xFF150066);

  static const Color lightSecondary = Color(0xFFB08820);
  static const Color lightOnSecondary = Color(0xFFFFFFFF);
  static const Color lightSecondaryContainer = Color(0xFFFDE9B8);
  static const Color lightOnSecondaryContainer = Color(0xFF261900);

  static const Color lightError = Color(0xFFBA1A1A);
  static const Color lightOnError = Color(0xFFFFFFFF);
  static const Color lightErrorContainer = Color(0xFFFFDAD6);
  static const Color lightOnErrorContainer = Color(0xFF410002);
}

/// Semantic color roles. Map Material 3 ColorScheme → Enpii brand.
///
/// Two flavors live here: dark (the original Etheric Cloud palette) and
/// light (a Material 3 tonal pairing of the same brand colors). All
/// values mirror the web app's `globals.css` so the mobile and web
/// skins stay visually in sync. Most code should resolve colors via
/// `Theme.of(context).colorScheme.*` rather than reading these
/// constants directly, so it follows the active theme.
class AppColors {
  const AppColors._();

  // ---- Dark scheme ---------------------------------------------------
  static const Color background = EnpiiPalette.black;
  static const Color surface = EnpiiPalette.surface;
  static const Color surfaceLow = EnpiiPalette.surfaceContainerLow;
  static const Color surfaceHigh = EnpiiPalette.surfaceContainerHigh;
  static const Color surfaceHighest = EnpiiPalette.surfaceContainerHighest;

  // Web names "primary" the light tint and "primary-container" the
  // deep brand. We expose the same shape on mobile so cross-platform
  // code (e.g. a shared API for theming) maps 1:1.
  static const Color primary = EnpiiPalette.primaryTint; // #C6C0FF
  static const Color onPrimary = EnpiiPalette.onPrimary; // #2C2179
  static const Color primaryContainer = EnpiiPalette.primaryContainer; // #3D348B
  static const Color onPrimaryContainer = EnpiiPalette.onPrimaryContainer; // #ABA3FF

  static const Color secondary = EnpiiPalette.secondary;
  static const Color onSecondary = EnpiiPalette.onSecondary;
  static const Color secondaryContainer = EnpiiPalette.secondaryContainer;
  static const Color onSecondaryContainer = EnpiiPalette.onSecondaryContainer;

  static const Color onSurface = EnpiiPalette.onSurface;
  static const Color onSurfaceVariant = EnpiiPalette.onSurfaceVariant;
  static const Color outline = EnpiiPalette.outline;
  static const Color outlineVariant = EnpiiPalette.outlineVariant;

  static const Color error = EnpiiPalette.error;
  static const Color onError = EnpiiPalette.onError;
  static const Color errorContainer = EnpiiPalette.errorContainer;
  static const Color onErrorContainer = EnpiiPalette.onErrorContainer;

  // Overlays
  static const Color scrim = Color(0x99000000); // black/60
  static const Color glassSurface = Color(0xCC1A1D27); // rgba(26,29,39,0.8) — matches web
  static const Color cardGlow = Color(0x0DFFFFFF); // inner-glow top edge

  // ---- Light scheme --------------------------------------------------
  static const Color lightBackground = EnpiiPalette.lightBackground;
  static const Color lightOnBackground = EnpiiPalette.lightOnBackground;
  static const Color lightSurface = EnpiiPalette.lightSurface;
  static const Color lightSurfaceLow = EnpiiPalette.lightSurfaceContainerLow;
  static const Color lightSurfaceHigh = EnpiiPalette.lightSurfaceContainerHigh;
  static const Color lightSurfaceHighest = EnpiiPalette.lightSurfaceContainerHighest;

  static const Color lightOnSurface = EnpiiPalette.lightOnSurface;
  static const Color lightOnSurfaceVariant = EnpiiPalette.lightOnSurfaceVariant;
  static const Color lightOutline = EnpiiPalette.lightOutline;
  static const Color lightOutlineVariant = EnpiiPalette.lightOutlineVariant;

  static const Color lightPrimary = EnpiiPalette.lightPrimary;
  static const Color lightOnPrimary = EnpiiPalette.lightOnPrimary;
  static const Color lightPrimaryContainer = EnpiiPalette.lightPrimaryContainer;
  static const Color lightOnPrimaryContainer = EnpiiPalette.lightOnPrimaryContainer;

  static const Color lightSecondary = EnpiiPalette.lightSecondary;
  static const Color lightOnSecondary = EnpiiPalette.lightOnSecondary;
  static const Color lightSecondaryContainer = EnpiiPalette.lightSecondaryContainer;
  static const Color lightOnSecondaryContainer = EnpiiPalette.lightOnSecondaryContainer;

  static const Color lightError = EnpiiPalette.lightError;
  static const Color lightOnError = EnpiiPalette.lightOnError;
  static const Color lightErrorContainer = EnpiiPalette.lightErrorContainer;
  static const Color lightOnErrorContainer = EnpiiPalette.lightOnErrorContainer;

  // Light glass toolbar — matches web rgba(251, 248, 255, 0.85).
  static const Color lightGlassSurface = Color(0xD9FBF8FF);
}