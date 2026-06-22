import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Typography tokens.
/// Headings: DM Sans (geometric, confident).
/// Body / label / metadata: Inter (legible).
class AppTypography {
  const AppTypography._();

  static TextStyle _dmSans({
    required double size,
    required FontWeight weight,
    double? height,
    double? letterSpacing,
    Color? color,
  }) {
    return GoogleFonts.dmSans(
      fontSize: size,
      fontWeight: weight,
      height: height,
      letterSpacing: letterSpacing,
      color: color,
    );
  }

  static TextStyle _inter({
    required double size,
    required FontWeight weight,
    double? height,
    double? letterSpacing,
    Color? color,
  }) {
    return GoogleFonts.inter(
      fontSize: size,
      fontWeight: weight,
      height: height,
      letterSpacing: letterSpacing,
      color: color,
    );
  }

  // Headings (DM Sans)
  static TextStyle displayXl = _dmSans(
    size: 48,
    weight: FontWeight.w700,
    height: 1.1,
    letterSpacing: -0.02 * 48,
  );
  static TextStyle headlineLg = _dmSans(
    size: 32,
    weight: FontWeight.w600,
    height: 1.2,
    letterSpacing: -0.01 * 32,
  );
  static TextStyle headlineLgMobile = _dmSans(
    size: 24,
    weight: FontWeight.w600,
    height: 1.2,
  );

  // Body (Inter)
  static TextStyle bodyLg = _inter(
    size: 18,
    weight: FontWeight.w400,
    height: 1.6,
  );
  static TextStyle bodyMd = _inter(
    size: 15,
    weight: FontWeight.w400,
    height: 1.5,
  );
  static TextStyle bodySm = _inter(
    size: 13,
    weight: FontWeight.w400,
    height: 1.4,
  );

  // Labels (Inter, uppercase, 5% letter spacing)
  static TextStyle labelSm = _inter(
    size: 12,
    weight: FontWeight.w600,
    height: 1,
    letterSpacing: 0.05 * 12,
  );

  // Metadata
  static TextStyle metadata = _inter(
    size: 13,
    weight: FontWeight.w400,
    height: 1.4,
  );

  /// Build the TextTheme used by ThemeData. Both [onSurface] and
  /// [onSurfaceVariant] must be supplied by the caller so the theme
  /// works in both brightness modes.
  static TextTheme buildTextTheme(Color onSurface, Color onSurfaceVariant) {
    return TextTheme(
      displayLarge: displayXl.copyWith(color: onSurface),
      displayMedium: headlineLg.copyWith(color: onSurface),
      displaySmall: headlineLgMobile.copyWith(color: onSurface),
      headlineLarge: headlineLg.copyWith(color: onSurface),
      headlineMedium: headlineLgMobile.copyWith(color: onSurface),
      headlineSmall: headlineLgMobile.copyWith(color: onSurface),
      titleLarge: headlineLgMobile.copyWith(color: onSurface),
      titleMedium: bodyMd.copyWith(color: onSurface, fontWeight: FontWeight.w600),
      bodyLarge: bodyLg.copyWith(color: onSurface),
      bodyMedium: bodyMd.copyWith(color: onSurface),
      bodySmall: metadata.copyWith(color: onSurfaceVariant),
      labelLarge: labelSm.copyWith(color: onSurface),
      labelMedium: labelSm.copyWith(color: onSurface),
      labelSmall: labelSm.copyWith(color: onSurfaceVariant),
    );
  }
}
