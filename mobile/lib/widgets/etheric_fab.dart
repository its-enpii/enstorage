import 'package:flutter/material.dart';

import '../theme/shadows.dart';

/// Gold circular FAB — plus icon only.
///
/// Positioning is the caller's responsibility. Use the same
/// `Positioned(right: AppSpacing.fabHorizontal, bottom: AppSpacing.fabBottom)`
/// pattern in every screen so the FAB is visually identical across the
/// app.
class EthericFab extends StatelessWidget {
  const EthericFab({super.key, required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Material(
      color: Colors.transparent,
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: Container(
          width: 56,
          height: 56,
          decoration: BoxDecoration(
            color: scheme.secondary,
            shape: BoxShape.circle,
            boxShadow: AppShadows.fabGold,
          ),
          child: Icon(
            Icons.add,
            color: scheme.onSecondary,
            size: 28,
          ),
        ),
      ),
    );
  }
}