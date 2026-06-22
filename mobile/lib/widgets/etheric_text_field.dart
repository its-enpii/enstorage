import 'package:flutter/material.dart';

import '../theme/radii.dart';
import '../theme/typography.dart';

/// "Punched-out" text field — bg = surface color, primary focus ring.
class EthericTextField extends StatelessWidget {
  const EthericTextField({
    super.key,
    required this.controller,
    this.hint,
    this.label,
    this.prefixIcon,
    this.suffix,
    this.obscureText = false,
    this.keyboardType,
    this.textInputAction,
    this.onSubmitted,
    this.autofocus = false,
    this.errorText,
  });

  final TextEditingController controller;
  final String? hint;
  final String? label;
  final IconData? prefixIcon;
  final Widget? suffix;
  final bool obscureText;
  final TextInputType? keyboardType;
  final TextInputAction? textInputAction;
  final ValueChanged<String>? onSubmitted;
  final bool autofocus;
  final String? errorText;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (label != null) ...[
          Text(
            label!,
            style: AppTypography.labelSm.copyWith(color: scheme.onSurfaceVariant),
          ),
          const SizedBox(height: 8),
        ],
        TextField(
          controller: controller,
          obscureText: obscureText,
          keyboardType: keyboardType,
          textInputAction: textInputAction,
          onSubmitted: onSubmitted,
          autofocus: autofocus,
          style: AppTypography.bodyMd.copyWith(color: scheme.onSurface),
          cursorColor: scheme.primary,
          decoration: InputDecoration(
            hintText: hint,
            errorText: errorText,
            prefixIcon: prefixIcon == null
                ? null
                : Icon(prefixIcon, color: scheme.onSurfaceVariant, size: 20),
            suffixIcon: suffix,
            filled: true,
            fillColor: scheme.surfaceContainer,
            contentPadding: const EdgeInsets.symmetric(
              horizontal: 16,
              vertical: 16,
            ),
            hintStyle: AppTypography.bodyMd.copyWith(color: scheme.outline),
            border: OutlineInputBorder(
              borderRadius: AppRadii.controlBorder,
              borderSide: BorderSide(color: scheme.outlineVariant, width: 1),
            ),
            enabledBorder: OutlineInputBorder(
              borderRadius: AppRadii.controlBorder,
              borderSide: BorderSide(color: scheme.outlineVariant, width: 1),
            ),
            focusedBorder: OutlineInputBorder(
              borderRadius: AppRadii.controlBorder,
              borderSide: BorderSide(color: scheme.primaryContainer, width: 2),
            ),
          ),
        ),
      ],
    );
  }
}