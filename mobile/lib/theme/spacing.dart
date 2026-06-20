/// Spacing tokens. From `.design/DESIGN.md`.
class AppSpacing {
  const AppSpacing._();

  // Mobile-tuned: container padding is 20 (vs 40 desktop)
  static const double containerPadding = 20;
  static const double innerPadding = 24; // card padding (slightly tighter than web's 32)
  static const double cardGap = 16; // grid gap on phone
  static const double sectionMargin = 32;

  // Components
  static const double bottomNavHeight = 72;
  static const double bottomNavBottom = 24; // gap from screen bottom
  static const double fabBottom = 96; // FAB sits above the bottom-nav pill
  static const double fabHorizontal = 24;

  /// Bottom padding for modal sheets so their last action clears the
  /// floating bottom-nav pill. = nav height (72) + small visual buffer.
  static const double sheetBottomPadding = 100;

  // Misc
  static const double screenMaxWidth = 480; // design canvas reference
  static const double iconSm = 20;
  static const double iconMd = 24;
  static const double iconLg = 32;
  static const double iconXl = 48;
}
