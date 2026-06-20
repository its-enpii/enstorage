import 'package:flutter/material.dart';

/// Corner radius tokens.
class AppRadii {
  const AppRadii._();

  // Cards: 24-28px "super-ellipse" feel
  static const double card = 24;
  static const double cardLg = 28;
  static const double cardSm = 20;

  // Buttons & inputs: 16px
  static const double control = 16;
  static const double controlSm = 12;

  // Pill: full radius
  static const double pill = 9999;
  static const BorderRadius cardBorder = BorderRadius.all(Radius.circular(card));
  static const BorderRadius cardLgBorder = BorderRadius.all(Radius.circular(cardLg));
  static const BorderRadius controlBorder = BorderRadius.all(Radius.circular(control));
  static const BorderRadius pillBorder = BorderRadius.all(Radius.circular(pill));
  static const BorderRadius topSheetBorder =
      BorderRadius.vertical(top: Radius.circular(cardLg));
}
