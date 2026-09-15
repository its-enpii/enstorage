import { LegalDocument } from '@/components/LegalDocument';
import { pageTitle } from '@/lib/serverMetadata';

export const metadata = pageTitle('legal.terms.title');

export default function TermsPage() {
  return (
    <LegalDocument
      titleKey="legal.terms.title"
      introKey="legal.terms.intro"
      sectionKeys={[
        'legal.terms.eligibility',
        'legal.terms.account',
        'legal.terms.acceptableUse',
        'legal.terms.yourContent',
        'legal.terms.service',
        'legal.terms.googleTerms',
        'legal.terms.termination',
        'legal.terms.liability',
        'legal.terms.warranty',
        'legal.terms.changes',
        'legal.terms.governingLaw',
        'legal.terms.contact',
      ]}
      lastUpdated="2026-09-14"
      pageTitleKey="legal.terms.title"
    />
  );
}