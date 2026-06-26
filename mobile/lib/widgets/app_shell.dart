import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../l10n/gen/app_localizations.dart';
import '../theme/breakpoints.dart';
import '../theme/radii.dart';
import '../theme/spacing.dart';
import 'glass_pill.dart';

/// Persistent shell hosting the primary navigation.
///
/// Phone (compact) → floating bottom pill (GlassPill).
/// Tablet (expanded) → NavigationRail on the left.
///
/// The icon is always FILLED. Only the purple circle background and the
/// label swap between states on the bottom pill; the rail uses Material
/// 3 NavigationRailDestination indicators.
///
/// Per-tab motion on the pill is *caused* by the label appearing/disappearing:
///   - Going INACTIVE → ACTIVE: label slides DOWN out of the slot +
///     fades out. The icon follows by sliding DOWN into the larger
///     purple circle (warna biru muncul).
///   - Going ACTIVE → INACTIVE: label slides UP into position + fades
///     in. The icon slides UP to make room, circle fades out (warna
///     biru memudar).
/// No hover, no splash, no scale-bounce.
class AppShell extends StatelessWidget {
  const AppShell({super.key, required this.navigationShell});

  final StatefulNavigationShell navigationShell;

  static const double _slotWidth = 64;
  static const double _slotHeight = 56;
  static const double _gap = 8;
  static const double _circleSize = 48;
  static const double _iconSize = 24;
  static const double _labelSize = 11;
  static const double _labelGap = 4;

  // Rail (tablet) layout — mirrors the bottom pill so the active
  // indicator looks identical on phone and tablet.
  static const double _railWidth = 80;
  static const double _railItemWidth = 64;
  static const double _railItemHeight = 72; // circle (48) + label (~15) + padding
  static const double _railItemGap = 8;

  // Layout for the rail icon slot (vertical stack: circle behind icon
  // on top, label below).
  static const double _railIconCenterY = 24; // center of the 48px circle
  static const double _railLabelTopVisible = 52;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    if (Breakpoints.isExpanded(context)) {
      return _buildRail(context, l10n);
    }
    return _buildPill(context, l10n);
  }

  // ---------------------------------------------------------------------------
  // Tablet: NavigationRail kiri.
  // ---------------------------------------------------------------------------

  Widget _buildRail(BuildContext context, AppLocalizations l10n) {
    return Scaffold(
      body: Row(
        children: [
          // Custom rail — matches the bottom-pill visual language:
          // circle indicator behind icon + label below. Not using
          // Material's NavigationRail because its default indicator
          // is a StadiumBorder that hugs the icon+label width and
          // looks "squished" (oval, not a perfect circle).
          Container(
            width: _railWidth,
            color: Theme.of(context).colorScheme.surface,
            child: SafeArea(
              child: Column(
                children: [
                  const SizedBox(height: 24),
                  _RailItem(
                    icon: Icons.home_rounded,
                    label: l10n.navHome,
                    active: navigationShell.currentIndex == 0,
                    onTap: () => navigationShell.goBranch(
                      0,
                      initialLocation: navigationShell.currentIndex == 0,
                    ),
                  ),
                  const SizedBox(height: _railItemGap),
                  _RailItem(
                    icon: Icons.folder_rounded,
                    label: l10n.navFiles,
                    active: navigationShell.currentIndex == 1,
                    onTap: () => navigationShell.goBranch(
                      1,
                      initialLocation: navigationShell.currentIndex == 1,
                    ),
                  ),
                  const SizedBox(height: _railItemGap),
                  _RailItem(
                    icon: Icons.settings_rounded,
                    label: l10n.navSettings,
                    active: navigationShell.currentIndex == 2,
                    onTap: () => navigationShell.goBranch(
                      2,
                      initialLocation: navigationShell.currentIndex == 2,
                    ),
                  ),
                ],
              ),
            ),
          ),
          const VerticalDivider(width: 1, thickness: 1),
          Expanded(child: navigationShell),
        ],
      ),
    );
  }

  // ---------------------------------------------------------------------------
  // Phone: floating bottom pill (kode existing).
  // ---------------------------------------------------------------------------

  Widget _buildPill(BuildContext context, AppLocalizations l10n) {
    return Stack(
      children: [
        navigationShell,
        Positioned(
          left: 0,
          right: 0,
          bottom: AppSpacing.bottomNavBottom,
          child: Center(
            child: GlassPill(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  _Slot(
                    width: _slotWidth,
                    height: _slotHeight,
                    child: _NavItem(
                      icon: Icons.home_rounded,
                      label: l10n.navHome,
                      active: navigationShell.currentIndex == 0,
                      onTap: () => navigationShell.goBranch(
                        0,
                        initialLocation:
                            navigationShell.currentIndex == 0,
                      ),
                    ),
                  ),
                  const SizedBox(width: _gap),
                  _Slot(
                    width: _slotWidth,
                    height: _slotHeight,
                    child: _NavItem(
                      icon: Icons.folder_rounded,
                      label: l10n.navFiles,
                      active: navigationShell.currentIndex == 1,
                      onTap: () => navigationShell.goBranch(
                        1,
                        initialLocation:
                            navigationShell.currentIndex == 1,
                      ),
                    ),
                  ),
                  const SizedBox(width: _gap),
                  _Slot(
                    width: _slotWidth,
                    height: _slotHeight,
                    child: _NavItem(
                      icon: Icons.settings_rounded,
                      label: l10n.navSettings,
                      active: navigationShell.currentIndex == 2,
                      onTap: () => navigationShell.goBranch(
                        2,
                        initialLocation:
                            navigationShell.currentIndex == 2,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ],
    );
  }
}

class _Slot extends StatelessWidget {
  const _Slot({
    required this.child,
    required this.width,
    required this.height,
  });
  final Widget child;
  final double width;
  final double height;
  @override
  Widget build(BuildContext context) {
    return SizedBox(width: width, height: height, child: child);
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.icon,
    required this.label,
    required this.active,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final bool active;
  final VoidCallback onTap;

  static const _animDuration = Duration(milliseconds: 360);
  static const _curve = Curves.easeInOutCubic;

  // Inactive layout (icon top, label below):
  //   icon  top=8  size=24  center y=20
  //   label top=36 size=11  center y=41
  // Active layout (icon centered in circle):
  //   circle top=4 size=48 center y=28
  //   icon  top=16 size=24  center y=28
  //   label  hidden (off-screen below)
  static const double _iconTopInactive = 8;
  static const double _iconTopActive = 16;
  static const double _labelTopVisible = 36;
  static const double _labelTopHidden = 56; // pushed below the slot

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: onTap,
      child: Stack(
        clipBehavior: Clip.hardEdge,
        children: [
            // Purple circle — scales + fades as one unit.
            Positioned(
              left: (AppShell._slotWidth - AppShell._circleSize) / 2,
              top: (AppShell._slotHeight - AppShell._circleSize) / 2,
              child: AnimatedScale(
                scale: active ? 1.0 : 0.0,
                duration: _animDuration,
                curve: _curve,
                child: AnimatedOpacity(
                  duration: _animDuration,
                  curve: _curve,
                  opacity: active ? 1.0 : 0.0,
                  child: Container(
                    width: AppShell._circleSize,
                    height: AppShell._circleSize,
                    decoration: BoxDecoration(
                      color: scheme.primaryContainer,
                      shape: BoxShape.circle,
                    ),
                  ),
                ),
              ),
            ),
            // Filled icon — slides DOWN when active, UP when inactive.
            AnimatedPositioned(
              duration: _animDuration,
              curve: _curve,
              left: (AppShell._slotWidth - AppShell._iconSize) / 2,
              top: active ? _iconTopActive : _iconTopInactive,
              child: Icon(
                icon,
                color: active
                    ? scheme.onPrimaryContainer
                    : scheme.onSurfaceVariant,
                size: AppShell._iconSize,
              ),
            ),
            // Label — slides DOWN + fades out when active,
            // slides UP + fades in when inactive.
            AnimatedPositioned(
              duration: _animDuration,
              curve: _curve,
              left: 0,
              right: 0,
              top: active ? _labelTopHidden : _labelTopVisible,
              child: AnimatedOpacity(
                duration: _animDuration,
                curve: _curve,
                opacity: active ? 0.0 : 1.0,
                child: Center(
                  child: SizedBox(
                    width: AppShell._slotWidth - 8,
                    child: FittedBox(
                      fit: BoxFit.scaleDown,
                      child: Text(
                        label,
                        maxLines: 1,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: scheme.onSurfaceVariant,
                          fontSize: AppShell._labelSize,
                          fontWeight: FontWeight.w600,
                          letterSpacing: 0.2,
                          decoration: TextDecoration.none,
                          decorationColor: Colors.transparent,
                        ),
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
    );
  }

  // No local re-exports — read AppShell._* directly.
}

/// Vertical mirror of [_NavItem] for the tablet rail.
/// Same circle-indicator visual so phone and tablet match.
class _RailItem extends StatelessWidget {
  const _RailItem({
    required this.icon,
    required this.label,
    required this.active,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final bool active;
  final VoidCallback onTap;

  static const _animDuration = Duration(milliseconds: 360);
  static const _curve = Curves.easeInOutCubic;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: onTap,
      child: SizedBox(
        width: AppShell._railItemWidth,
        height: AppShell._railItemHeight,
        child: Stack(
          clipBehavior: Clip.hardEdge,
          children: [
            // Circle indicator — scales + fades as one unit.
            // Vertically centered on the icon row (top half of slot).
            Positioned(
              left: (AppShell._railItemWidth - AppShell._circleSize) / 2,
              top: AppShell._railIconCenterY - AppShell._circleSize / 2,
              child: AnimatedScale(
                scale: active ? 1.0 : 0.0,
                duration: _animDuration,
                curve: _curve,
                child: AnimatedOpacity(
                  duration: _animDuration,
                  curve: _curve,
                  opacity: active ? 1.0 : 0.0,
                  child: Container(
                    width: AppShell._circleSize,
                    height: AppShell._circleSize,
                    decoration: BoxDecoration(
                      color: scheme.primaryContainer,
                      shape: BoxShape.circle,
                    ),
                  ),
                ),
              ),
            ),
            // Filled icon — centered horizontally, vertically aligned
            // to the circle indicator.
            Positioned(
              left: (AppShell._railItemWidth - AppShell._iconSize) / 2,
              top: AppShell._railIconCenterY - AppShell._iconSize / 2,
              child: Icon(
                icon,
                color: active
                    ? scheme.onPrimaryContainer
                    : scheme.onSurfaceVariant,
                size: AppShell._iconSize,
              ),
            ),
            // Label — below the circle, fades between onSurface and
            // onSurfaceVariant. Position-based so it stays put as the
            // circle animates.
            AnimatedPositioned(
              duration: _animDuration,
              curve: _curve,
              left: 0,
              right: 0,
              top: AppShell._railLabelTopVisible,
              child: Center(
                child: SizedBox(
                  width: AppShell._railItemWidth - 4,
                  child: FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      label,
                      maxLines: 1,
                      textAlign: TextAlign.center,
                      style: TextStyle(
                        color: active
                            ? scheme.onSurface
                            : scheme.onSurfaceVariant,
                        fontSize: AppShell._labelSize,
                        fontWeight: FontWeight.w600,
                        letterSpacing: 0.2,
                        decoration: TextDecoration.none,
                        decorationColor: Colors.transparent,
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}