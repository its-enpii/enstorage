import { LegalDocument } from '@/components/LegalDocument';

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
        'legal.terms.termination',
        'legal.terms.liability',
        'legal.terms.changes',
        'legal.terms.governingLaw',
        'legal.terms.contact',
      ]}
      lastUpdated="2026-06-25"
      pageTitleKey="legal.terms.title"
    />
  );
}