'use client';

import { useTranslation } from 'react-i18next';
import { useEffect } from 'react';

type Section = { heading: string; body: string };

type Props = {
  titleKey: string; // e.g. "legal.privacy.title"
  introKey: string; // e.g. "legal.privacy.intro"
  sectionKeys: string[]; // e.g. ["legal.privacy.dataWeCollect", ...]
  lastUpdated: string; // ISO date or display string
  pageTitleKey: string;
};

/**
 * Renders a long-form legal document (Privacy Policy / Terms).
 * Resolves all content through i18n so it follows the active locale.
 * Used by /legal/privacy and /legal/terms.
 */
export function LegalDocument({
  titleKey,
  introKey,
  sectionKeys,
  lastUpdated,
  pageTitleKey,
}: Props) {
  const { t } = useTranslation();

  useEffect(() => {
    const title = t(pageTitleKey);
    document.title = title;
  }, [t, pageTitleKey]);

  const sections: Section[] = sectionKeys.map((k) => ({
    heading: t(k),
    body: t(`${k}Body`),
  }));

  return (
    <main className="min-h-screen bg-background text-on-surface">
      <div className="mx-auto w-full max-w-3xl px-4 py-10 sm:py-16">
        <header className="mb-8">
          <h1 className="font-display text-headline-lg text-on-surface">
            {t(titleKey)}
          </h1>
          <p className="mt-2 text-metadata text-on-surface-variant">
            {t('legal.lastUpdated')}: {lastUpdated}
          </p>
        </header>

        <article className="rounded-card bg-surface p-inner-padding shadow-inner-glow">
          <p className="text-body text-on-surface-variant mb-6">
            {t(introKey)}
          </p>

          {sections.map((s, i) => (
            <section key={i} className="mt-6 first:mt-0">
              <h2 className="font-display text-title text-on-surface">
                {s.heading}
              </h2>
              <div className="mt-2 space-y-2 text-body text-on-surface-variant whitespace-pre-line">
                {s.body}
              </div>
            </section>
          ))}
        </article>

        <footer className="mt-8 text-metadata text-on-surface-variant">
          <a
            href="/legal/privacy"
            className="text-primary hover:underline"
          >
            {t('legal.privacy.title')}
          </a>
          {' · '}
          <a
            href="/legal/terms"
            className="text-primary hover:underline"
          >
            {t('legal.terms.title')}
          </a>
        </footer>
      </div>
    </main>
  );
}