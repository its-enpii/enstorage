import 'package:flutter/material.dart';

import '../theme/radii.dart';
import '../theme/typography.dart';

/// Shared chrome + body for a "list menu" bottom sheet — the bottom-sheet
/// pattern used by Sort, Filter, and the FAB "Buat Baru" menu.
///
/// Renders the standard handle bar and title, then any [children] (typically
/// a list of [ListMenuTile]s) inside a [SafeArea]. The caller still owns the
/// `showModalBottomSheet` call and any trailing confirm/reset buttons.
///
/// Usage:
/// ```dart
/// showModalBottomSheet(
///   context: ctx,
///   builder: (ctx) => NavAwareSheet(
///     child: ListMenuSheet(
///       title: l10n.sortSheetTitle,
///       children: [
///         ListMenuTile(icon: ..., label: ..., ...),
///       ],
///     ),
///   ),
/// );
/// ```
class ListMenuSheet extends StatelessWidget {
  const ListMenuSheet({
    super.key,
    required this.title,
    required this.children,
    this.scrollable = false,
  });

  final String title;
  final List<Widget> children;

  /// When true, children are wrapped in a [SingleChildScrollView] so
  /// long content (Filter: 2 chip groups + buttons) doesn't overflow.
  /// The handle bar and title stay pinned outside the scroll.
  final bool scrollable;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 4,
              margin: const EdgeInsets.only(top: 12),
              decoration: BoxDecoration(
                color: scheme.onSurface.withValues(alpha: 0.20),
                borderRadius: AppRadii.pillBorder,
              ),
            ),
          ),
          const SizedBox(height: 16),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 20),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(title, style: AppTypography.headlineLgMobile),
            ),
          ),
          const SizedBox(height: 12),
          if (scrollable)
            Flexible(
              child: SingleChildScrollView(
                padding: const EdgeInsets.fromLTRB(0, 0, 0, 12),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: children,
                ),
              ),
            )
          else
            ...children,
        ],
      ),
    );
  }
}

/// Single row inside a [ListMenuSheet]. 56px tall, circular icon badge
/// (40×40) on the left, [bodyLg] label, and an optional [trailing] slot for
/// switches / icon-buttons.
///
/// Pass [iconBg] to render the leading icon inside a colored circular
/// badge (the standard look). Leave it null to fall back to a bare icon
/// (20px) — useful for tighter rows where the badge would feel heavy.
class ListMenuTile extends StatelessWidget {
  const ListMenuTile({
    super.key,
    required this.icon,
    required this.iconFg,
    required this.label,
    this.iconBg,
    this.selected = false,
    this.onTap,
    this.trailing,
  });

  final IconData icon;
  final Color iconFg;
  final String label;
  final Color? iconBg;
  final bool selected;
  final VoidCallback? onTap;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final hasBadge = iconBg != null;
    final onSurface = Theme.of(context).colorScheme.onSurface;
    return Material(
      color: Colors.transparent,
      borderRadius: AppRadii.controlBorder,
      child: InkWell(
        borderRadius: AppRadii.controlBorder,
        onTap: onTap,
        child: SizedBox(
          height: 56,
          child: Row(
            children: [
              const SizedBox(width: 20),
              if (hasBadge)
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: iconBg,
                    shape: BoxShape.circle,
                  ),
                  child: Icon(icon, color: iconFg, size: 22),
                )
              else
                Icon(icon, color: iconFg, size: 20),
              SizedBox(width: hasBadge ? 16 : 12),
              Expanded(
                child: Text(
                  label,
                  style: AppTypography.bodyLg.copyWith(
                    color: onSurface,
                    fontWeight: selected ? FontWeight.w700 : FontWeight.w600,
                  ),
                ),
              ),
              if (trailing != null) ...[
                trailing!,
                const SizedBox(width: 20),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
