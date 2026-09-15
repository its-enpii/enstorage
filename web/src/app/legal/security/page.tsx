import { LegalDocument } from '@/components/LegalDocument';
import { pageTitle } from '@/lib/serverMetadata';

export const metadata = pageTitle('legal.security.title');

export default function SecurityPage() {
  return (
    <LegalDocument
      titleKey="legal.security.title"
      introKey="legal.security.intro"
      sectionKeys={[
        'legal.security.architecture',
        'legal.security.transport',
        'legal.security.atRest',
        'legal.security.credentials',
        'legal.security.scopes',
        'legal.security.limitedUse',
        'legal.security.isolation',
        'legal.security.sharing',
        'legal.security.logging',
        'legal.security.userControls',
        'legal.security.deletion',
        'legal.security.userResponsibilities',
        'legal.security.vulnerability',
        'legal.security.status',
      ]}
      lastUpdated="2026-09-14"
      pageTitleKey="legal.security.title"
    />
  );
}
