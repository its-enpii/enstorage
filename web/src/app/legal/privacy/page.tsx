import { LegalDocument } from '@/components/LegalDocument';

export default function PrivacyPage() {
  return (
    <LegalDocument
      titleKey="legal.privacy.title"
      introKey="legal.privacy.intro"
      sectionKeys={[
        'legal.privacy.dataWeCollect',
        'legal.privacy.howWeUse',
        'legal.privacy.sharing',
        'legal.privacy.thirdParty',
        'legal.privacy.security',
        'legal.privacy.retention',
        'legal.privacy.yourRights',
        'legal.privacy.changes',
        'legal.privacy.contact',
      ]}
      lastUpdated="2026-06-25"
      pageTitleKey="legal.privacy.title"
    />
  );
}