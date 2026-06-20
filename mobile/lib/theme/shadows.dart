import 'package:flutter/material.dart';

/// Elevation tokens. No borders — depth via tonal stacking + soft shadows.
class AppShadows {
  const AppShadows._();

  // Soft ambient — `0px 20px 40px rgba(0,0,0,0.4)`
  static const List<BoxShadow> xl = [
    BoxShadow(
      color: Color(0x66000000), // 40% black
      blurRadius: 40,
      offset: Offset(0, 20),
    ),
  ];

  // FAB glow — gold tinted
  static const List<BoxShadow> fabGold = [
    BoxShadow(
      color: Color(0x4DE6AF2E), // 30% gold
      blurRadius: 30,
      offset: Offset(0, 10),
    ),
  ];

  // Subtle — for selected/active states
  static const List<BoxShadow> primaryGlow = [
    BoxShadow(
      color: Color(0x663D348B), // 40% primary
      blurRadius: 20,
      offset: Offset(0, 0),
    ),
  ];

  // Subtle gold glow — for selected items in the files grid.
  static const List<BoxShadow> secondaryGlow = [
    BoxShadow(
      color: Color(0x4DE6AF2E), // 30% gold (matches fabGold)
      blurRadius: 16,
      offset: Offset(0, 0),
    ),
  ];

  // Inner glow used on cards: inset 0 1px 0 rgba(255,255,255,0.05)
  static const List<BoxShadow> innerGlow = [
    BoxShadow(
      color: Color(0x0DFFFFFF),
      blurRadius: 0,
      offset: Offset(0, 1),
    ),
  ];
}
