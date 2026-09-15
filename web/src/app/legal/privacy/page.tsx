import { LegalDocument } from '@/components/LegalDocument';
import { pageTitle } from '@/lib/serverMetadata';

export const metadata = pageTitle('legal.privacy.title');

export default function PrivacyPage() {
  return <LegalDocument doc="privacy" lastUpdated="2026-09-15" />;
}
