import 'package:flutter/material.dart';

import 'colors.dart';
import 'radii.dart';
import 'typography.dart';

/// Etheric Cloud theme — built from the Enpii palette. Two schemes live
/// here: a dark theme (the original Etheric Cloud palette) and a light
/// theme that reuses the same brand colors with a tonal surface scale
/// appropriate for white backgrounds.
class AppTheme {
  const AppTheme._();

  static ThemeData dark() => _build(brightness: Brightness.dark);
  static ThemeData light() => _build(brightness: Brightness.light);

  static ThemeData _build({required Brightness brightness}) {
    final isDark = brightness == Brightness.dark;

    // ---- Surface & text tokens (depend on brightness) ----------------
    final background =
        isDark ? AppColors.background : AppColors.lightBackground;
    final surface = isDark ? AppColors.surface : AppColors.lightSurface;
    final surfaceLow =
        isDark ? AppColors.surfaceLow : AppColors.lightSurfaceLow;
    final surfaceHigh =
        isDark ? AppColors.surfaceHigh : AppColors.lightSurfaceHigh;
    final surfaceHighest =
        isDark ? AppColors.surfaceHighest : AppColors.lightSurfaceHighest;
    final onSurface =
        isDark ? AppColors.onSurface : AppColors.lightOnSurface;
    final onSurfaceVariant = isDark
        ? AppColors.onSurfaceVariant
        : AppColors.lightOnSurfaceVariant;
    final outline = isDark ? AppColors.outline : AppColors.lightOutline;
    final outlineVariant =
        isDark ? AppColors.outlineVariant : AppColors.lightOutlineVariant;

    // ---- Brand & semantic tokens (depend on brightness) --------------
    final primary = isDark ? AppColors.primary : AppColors.lightPrimary;
    final onPrimary =
        isDark ? AppColors.onPrimary : AppColors.lightOnPrimary;
    final primaryContainer = isDark
        ? AppColors.primaryContainer
        : AppColors.lightPrimaryContainer;
    final onPrimaryContainer = isDark
        ? AppColors.onPrimaryContainer
        : AppColors.lightOnPrimaryContainer;

    final secondary =
        isDark ? AppColors.secondary : AppColors.lightSecondary;
    final onSecondary =
        isDark ? AppColors.onSecondary : AppColors.lightOnSecondary;
    final secondaryContainer = isDark
        ? AppColors.secondaryContainer
        : AppColors.lightSecondaryContainer;
    final onSecondaryContainer = isDark
        ? AppColors.onSecondaryContainer
        : AppColors.lightOnSecondaryContainer;

    final error = isDark ? AppColors.error : AppColors.lightError;
    final onError = isDark ? AppColors.onError : AppColors.lightOnError;
    final errorContainer = isDark
        ? AppColors.errorContainer
        : AppColors.lightErrorContainer;
    final onErrorContainer = isDark
        ? AppColors.onErrorContainer
        : AppColors.lightOnErrorContainer;

    final colorScheme = ColorScheme(
      brightness: brightness,
      primary: primary,
      onPrimary: onPrimary,
      primaryContainer: primaryContainer,
      onPrimaryContainer: onPrimaryContainer,
      secondary: secondary,
      onSecondary: onSecondary,
      secondaryContainer: secondaryContainer,
      onSecondaryContainer: onSecondaryContainer,
      surface: surface,
      onSurface: onSurface,
      surfaceContainerLowest: surfaceLow,
      surfaceContainerLow: surfaceLow,
      surfaceContainer: surface,
      surfaceContainerHigh: surfaceHigh,
      surfaceContainerHighest: surfaceHighest,
      onSurfaceVariant: onSurfaceVariant,
      outline: outline,
      outlineVariant: outlineVariant,
      error: error,
      onError: onError,
      errorContainer: errorContainer,
      onErrorContainer: onErrorContainer,
      scrim: AppColors.scrim,
    );

    final textTheme =
        AppTypography.buildTextTheme(onSurface, onSurfaceVariant);

    // In light mode the AppBar uses the light primary container as its
    // title color — matches the dark "purple headline" pattern but
    // against a white backdrop.
    final appBarTitleColor =
        isDark ? AppColors.primary : AppColors.lightPrimary;

    return ThemeData(
      useMaterial3: true,
      brightness: brightness,
      colorScheme: colorScheme,
      scaffoldBackgroundColor: background,
      canvasColor: background,
      splashFactory: InkSparkle.splashFactory,
      visualDensity: VisualDensity.adaptivePlatformDensity,
      textTheme: textTheme,
      // Strip all borders. Use tonal stacking instead.
      dividerTheme: const DividerThemeData(
        color: Colors.transparent,
        thickness: 0,
        space: 0,
      ),
      appBarTheme: AppBarTheme(
        backgroundColor: background,
        surfaceTintColor: Colors.transparent,
        foregroundColor: onSurface,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: AppTypography.headlineLgMobile.copyWith(
          color: appBarTitleColor,
        ),
      ),
      cardTheme: CardThemeData(
        color: surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: const RoundedRectangleBorder(borderRadius: AppRadii.cardBorder),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: surface,
        hintStyle: AppTypography.bodyMd.copyWith(color: outline),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 14,
        ),
        border: OutlineInputBorder(
          borderRadius: AppRadii.controlBorder,
          borderSide: BorderSide(color: outlineVariant, width: 1),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: AppRadii.controlBorder,
          borderSide: BorderSide(color: outlineVariant, width: 1),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: AppRadii.controlBorder,
          borderSide: BorderSide(color: primaryContainer, width: 2),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: AppRadii.controlBorder,
          borderSide: BorderSide(color: error, width: 1),
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: primaryContainer,
          foregroundColor: onPrimaryContainer,
          elevation: 0,
          textStyle: AppTypography.labelSm,
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14),
          shape: const RoundedRectangleBorder(
            borderRadius: AppRadii.controlBorder,
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: onPrimaryContainer,
          textStyle: AppTypography.labelSm,
        ),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: surface,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        shape: const RoundedRectangleBorder(borderRadius: AppRadii.topSheetBorder),
        showDragHandle: false,
        dragHandleColor: onSurfaceVariant,
      ),
      iconTheme: IconThemeData(color: onSurface, size: 24),
      progressIndicatorTheme: ProgressIndicatorThemeData(
        color: primary,
        linearTrackColor: surfaceHigh,
        circularTrackColor: surfaceHigh,
      ),
      snackBarTheme: SnackBarThemeData(
        backgroundColor: surfaceHigh,
        contentTextStyle: AppTypography.bodyMd.copyWith(color: onSurface),
        behavior: SnackBarBehavior.floating,
        // Push floating snackbars well above the bottom-nav pill so the
        // two never overlap. bottomNavHeight (72) + bottomNavBottom (24)
        // + ~32 of breathing room = 128. Matches `sheetBottomPadding`
        // in spacing.dart which encodes "clear the bottom-nav".
        insetPadding: const EdgeInsets.fromLTRB(16, 0, 16, 128),
        shape: RoundedRectangleBorder(
          borderRadius: AppRadii.pillBorder,
          side: BorderSide(
            color: outlineVariant.withValues(alpha: 0.3),
            width: 1,
          ),
        ),
      ),
    );
  }
}