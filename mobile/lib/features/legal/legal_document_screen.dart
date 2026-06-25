import 'package:flutter/material.dart';

import '../../l10n/gen/app_localizations.dart';
import '../../theme/spacing.dart';
import '../../theme/typography.dart';

/// Renders a long-form legal document (Privacy Policy / Terms of Service).
///
/// Sections are supplied by the caller as a list of (title, body) pairs,
/// typically sourced from `AppLocalizations` keys (e.g. `legalPrivacySection1Title`).
/// This keeps the screen generic so both privacy and terms reuse it.
///
/// Last-updated date is passed in as a display string so the screen does
/// not need to know the calendar — update it from the caller when the
/// legal text changes.
class LegalDocumentScreen extends StatelessWidget {
  const LegalDocumentScreen({
    super.key,
    required this.title,
    required this.lastUpdated,
    required this.intro,
    required this.sections,
  });

  /// Document title (already resolved in the caller's locale). Shows in
  /// the app bar.
  final String title;

  /// Last-updated date string (already formatted in the caller's locale).
  final String lastUpdated;

  /// Intro paragraph that appears above the section list.
  final String intro;

  /// Ordered list of section heading/body pairs.
  final List<LegalSection> sections;

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    final scheme = Theme.of(context).colorScheme;

    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: SafeArea(
        top: false,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(
            AppSpacing.containerPadding,
            12,
            AppSpacing.containerPadding,
            40,
          ),
          children: [
            // "Last updated: …" — uses the same phrase that appears in
            // the web version for cross-platform consistency.
            Text(
              '${l10n.legalLastUpdated}: $lastUpdated',
              style: AppTypography.metadata.copyWith(
                color: scheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 20),

            // Intro paragraph
            Text(
              intro,
              style: AppTypography.bodyMd.copyWith(
                color: scheme.onSurface,
                height: 1.6,
              ),
            ),
            const SizedBox(height: 24),

            // Sections
            ...sections.expand((s) => [
                  Text(
                    s.title,
                    style: AppTypography.bodyLg.copyWith(
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    s.body,
                    style: AppTypography.bodyMd.copyWith(
                      color: scheme.onSurfaceVariant,
                      height: 1.6,
                    ),
                  ),
                  const SizedBox(height: 20),
                ]),
          ],
        ),
      ),
    );
  }
}

/// A single heading + body pair inside a [LegalDocumentScreen].
class LegalSection {
  const LegalSection({required this.title, required this.body});
  final String title;
  final String body;
}

/// Convenience: build the Privacy Policy from AppLocalizations.
///
/// Sections are listed in display order; numbering in the title strings
/// already follows the structure used in the web legal pages so the
/// two surfaces stay in sync.
List<LegalSection> privacySections(AppLocalizations l10n) => [
      LegalSection(title: l10n.legalPrivacySection1Title, body: l10n.legalPrivacySection1Body),
      LegalSection(title: l10n.legalPrivacySection2Title, body: l10n.legalPrivacySection2Body),
      LegalSection(title: l10n.legalPrivacySection3Title, body: l10n.legalPrivacySection3Body),
      LegalSection(title: l10n.legalPrivacySection4Title, body: l10n.legalPrivacySection4Body),
      LegalSection(title: l10n.legalPrivacySection5Title, body: l10n.legalPrivacySection5Body),
      LegalSection(title: l10n.legalPrivacySection6Title, body: l10n.legalPrivacySection6Body),
      LegalSection(title: l10n.legalPrivacySection7Title, body: l10n.legalPrivacySection7Body),
      LegalSection(title: l10n.legalPrivacySection8Title, body: l10n.legalPrivacySection8Body),
      LegalSection(title: l10n.legalPrivacySection9Title, body: l10n.legalPrivacySection9Body),
    ];

/// Convenience: build the Terms of Service from AppLocalizations.
List<LegalSection> termsSections(AppLocalizations l10n) => [
      LegalSection(title: l10n.legalTermsSection1Title, body: l10n.legalTermsSection1Body),
      LegalSection(title: l10n.legalTermsSection2Title, body: l10n.legalTermsSection2Body),
      LegalSection(title: l10n.legalTermsSection3Title, body: l10n.legalTermsSection3Body),
      LegalSection(title: l10n.legalTermsSection4Title, body: l10n.legalTermsSection4Body),
      LegalSection(title: l10n.legalTermsSection5Title, body: l10n.legalTermsSection5Body),
      LegalSection(title: l10n.legalTermsSection6Title, body: l10n.legalTermsSection6Body),
      LegalSection(title: l10n.legalTermsSection7Title, body: l10n.legalTermsSection7Body),
      LegalSection(title: l10n.legalTermsSection8Title, body: l10n.legalTermsSection8Body),
      LegalSection(title: l10n.legalTermsSection9Title, body: l10n.legalTermsSection9Body),
      LegalSection(title: l10n.legalTermsSection10Title, body: l10n.legalTermsSection10Body),
    ];