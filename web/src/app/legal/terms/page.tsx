import { LegalDocument } from '@/components/LegalDocument';
import { pageTitle } from '@/lib/serverMetadata';

export const metadata = pageTitle('legal.terms.title');

export default function TermsPage() {
  return <LegalDocument doc="terms" lastUpdated="2026-09-15" />;
}
