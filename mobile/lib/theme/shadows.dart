import 'package:flutter/material.dart';

/// Elevation tokens. No borders — depth via tonal stacking + soft shadows.
///
/// Most tokens are brightness-dependent: light mode uses lighter, more
/// diffuse shadows (web `--shadow-ambient` is `rgba(0,0,0,0.08)` in
/// light vs `0.4` in dark). Each brightness-dependent token is exposed
/// as a static method that takes the current [Brightness] so callers
/// can resolve them through `Theme.of(context)`.
class AppShadows {
  const AppShadows._();

  // FAB glow — gold tinted. Theme-invariant: gold looks the same on
  // both backgrounds.
  static const List<BoxShadow> fabGold = [
    BoxShadow(
      color: Color(0x4DE6AF2E), // 30% gold
      blurRadius: 30,
      offset: Offset(0, 10),
    ),
  ];

  // Subtle gold glow — for selected items in the files grid. Same in
  // both modes.
  static const List<BoxShadow> secondaryGlow = [
    BoxShadow(
      color: Color(0x4DE6AF2E),
      blurRadius: 16,
      offset: Offset(0, 0),
    ),
  ];

  /// Soft ambient card shadow. Dark: 40% black, 40 blur. Light: 8%
  /// black, 16 blur — matches web.
  static List<BoxShadow> ambient(Brightness brightness) {
    if (brightness == Brightness.light) {
      return const [
        BoxShadow(
          color: Color(0x14000000), // ~8% black
          blurRadius: 16,
          offset: Offset(0, 4),
        ),
      ];
    }
    return const [
      BoxShadow(
        color: Color(0x66000000), // 40% black
        blurRadius: 40,
        offset: Offset(0, 20),
      ),
    ];
  }

  /// Inner glow on cards (top edge highlight). Dark: white at 5%.
  /// Light: white at 60% — matches web `--shadow-inner-glow`.
  static List<BoxShadow> innerGlow(Brightness brightness) {
    if (brightness == Brightness.light) {
      return const [
        BoxShadow(
          color: Color(0x99FFFFFF), // 60% white
          blurRadius: 0,
          offset: Offset(0, 1),
        ),
      ];
    }
    return const [
      BoxShadow(
        color: Color(0x0DFFFFFF), // 5% white
        blurRadius: 0,
        offset: Offset(0, 1),
      ),
    ];
  }

  /// Selected card glow. Dark: 40% primary at 20 blur. Light: 20%
  /// primary at 12 blur — matches web `--shadow-selected-glow`.
  static List<BoxShadow> primaryGlow(Brightness brightness) {
    if (brightness == Brightness.light) {
      return const [
        BoxShadow(
          color: Color(0x335B53AA), // 20% of light primary
          blurRadius: 12,
          offset: Offset(0, 0),
        ),
      ];
    }
    return const [
      BoxShadow(
        color: Color(0x663D348B), // 40% of dark primary container
        blurRadius: 20,
        offset: Offset(0, 0),
      ),
    ];
  }
}