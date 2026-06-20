import 'package:flutter/material.dart';

/// Wrap modal sheet content in a Material with the standard top-rounded
/// corner radius. No bottom padding — the sheet extends to the screen
/// bottom and sits in front of the floating bottom-nav pill while open.
/// The nav is hidden behind the sheet's scrim, which is the expected
/// modal behavior.
///
/// Usage:
/// ```dart
/// showModalBottomSheet(
///   builder: (ctx) => NavAwareSheet(child: MySheetContent()),
/// );
/// ```
class NavAwareSheet extends StatelessWidget {
  const NavAwareSheet({super.key, required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Theme.of(context).colorScheme.surface,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
      ),
      child: child,
    );
  }
}
