import 'package:flutter/material.dart';

import '../../l10n/gen/app_localizations.dart';
import 'legal_document_screen.dart';

/// Terms of Service screen — accessible from Settings → Legal and from
/// the Login screen link.
class TermsOfServiceScreen extends StatelessWidget {
  const TermsOfServiceScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context)!;
    return LegalDocumentScreen(
      title: l10n.legalTermsTitle,
      lastUpdated: '25 Juni 2026',
      intro: l10n.legalTermsIntro,
      sections: termsSections(l10n),
    );
  }
}