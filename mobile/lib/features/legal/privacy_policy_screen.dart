import 'package:flutter/material.dart';

import '../../l10n/gen/app_localizations.dart';
import 'legal_document_screen.dart';

/// Privacy Policy screen — accessible from Settings → Legal → Privacy Policy.
class PrivacyPolicyScreen extends StatelessWidget {
  const PrivacyPolicyScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    return LegalDocumentScreen(
      title: l10n.legalPrivacyTitle,
      lastUpdated: '25 Juni 2026',
      intro: l10n.legalPrivacyIntro,
      sections: privacySections(l10n),
    );
  }
}