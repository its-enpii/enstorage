import 'package:flutter/material.dart';

import '../theme/colors.dart';
import '../theme/radii.dart';
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
    return Material(
      color: Colors.transparent,
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onTap,
        child: Container(
          width: 56,
          height: 56,
          decoration: const BoxDecoration(
            color: AppColors.secondary,
            shape: BoxShape.circle,
            boxShadow: AppShadows.fabGold,
          ),
          child: const Icon(
            Icons.add,
            color: AppColors.onSecondary,
            size: 28,
          ),
        ),
      ),
    );
  }
}
