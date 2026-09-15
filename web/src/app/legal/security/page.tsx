import { LegalDocument } from '@/components/LegalDocument';
import { pageTitle } from '@/lib/serverMetadata';

export const metadata = pageTitle('legal.security.title');

export default function SecurityPage() {
  return <LegalDocument doc="security" lastUpdated="2026-09-15" />;
}
