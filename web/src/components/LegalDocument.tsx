'use client';

import { useEffect, useMemo, useState, type ComponentType } from 'react';
import Link from 'next/link';
import { Description, Email, Policy, Shield, Toc } from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Card, CardIconBox } from '@/components/Card';
import { Chip } from '@/components/Chip';
import { InlineMarkdown } from '@/components/InlineMarkdown';
import { PublicShell } from '@/components/PublicShell';
import { usePageTitle } from '@/lib/usePageTitle';
import { CONTACT_EMAIL } from '@/lib/site';

export type LegalDocId = 'privacy' | 'terms' | 'security';

type LegalIcon = ComponentType<{ className?: string }>;

type DocMeta = {
  id: LegalDocId;
  href: string;
  tabKey: string;
  tabShortKey: string;
  icon: LegalIcon;
  /** Article headings, in document order — drives both the TOC and the body. */
  sectionKeys: string[];
};

/** Shared registry: drives the switcher tabs, the TOC, and the document body. */
const DOCS: DocMeta[] = [
  {
    id: 'privacy',
    href: '/legal/privacy',
    tabKey: 'legal.privacy.title',
    tabShortKey: 'legal.privacy.tab',
    icon: Description,
    sectionKeys: [
      'legal.privacy.dataWeCollect',
      'legal.privacy.howWeUse',
      'legal.privacy.googleScopes',
      'legal.privacy.limitedUse',
      'legal.privacy.sharing',
      'legal.privacy.thirdParty',
      'legal.privacy.security',
      'legal.privacy.retention',
      'legal.privacy.yourRights',
      'legal.privacy.changes',
      'legal.privacy.contact',
    ],
  },
  {
    id: 'terms',
    href: '/legal/terms',
    tabKey: 'legal.terms.title',
    tabShortKey: 'legal.terms.tab',
    icon: Shield,
    sectionKeys: [
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
    ],
  },
  {
    id: 'security',
    href: '/legal/security',
    tabKey: 'legal.security.title',
    tabShortKey: 'legal.security.tab',
    icon: Policy,
    sectionKeys: [
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
    ],
  },
];

export function findLegalDoc(id: LegalDocId): DocMeta {
  return DOCS.find((doc) => doc.id === id) ?? DOCS[0];
}

function useActiveSection(ids: string[]) {
  const [active, setActive] = useState<string>(ids[0] ?? '');

  useEffect(() => {
    const visible = new Set<string>();
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          const id = (entry.target as HTMLElement).id;
          if (entry.isIntersecting) visible.add(id);
          else visible.delete(id);
        }
        const first = ids.find((id) => visible.has(id));
        if (first) setActive(first);
      },
      { rootMargin: '-160px 0px -60% 0px', threshold: [0, 0.25, 0.5, 1] },
    );
    for (const id of ids) {
      const el = document.getElementById(id);
      if (el) observer.observe(el);
    }
    return () => observer.disconnect();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ids.join('|')]);

  return active;
}

/**
 * Portal-only tab strip for moving between the three legal documents. It lives
 * inside the page content, not in the header — the header is shared and stays
 * identical across every public surface.
 */
function DocumentSwitcher({ doc }: { doc: DocMeta }) {
  const { t } = useTranslation();

  return (
    <nav
      className="sticky top-16 z-40 border-b border-outline-variant/20 bg-background/95 backdrop-blur-md"
      aria-label={t('legal.switcherAria')}
    >
      <div className="mx-auto flex w-full max-w-6xl gap-1 overflow-x-auto px-4 sm:px-6">
        {DOCS.map((item) => {
          const Icon = item.icon;
          const active = item.id === doc.id;
          return (
            <Link
              key={item.id}
              href={item.href}
              aria-current={active ? 'page' : undefined}
              className={clsx(
                'flex shrink-0 items-center gap-2 border-b-2 px-3.5 py-3 text-sm font-semibold no-underline transition-colors',
                active
                  ? 'border-primary text-primary'
                  : 'border-transparent text-on-surface-variant hover:text-on-surface',
              )}
            >
              <Icon className="!text-lg" />
              {t(item.tabShortKey)}
            </Link>
          );
        })}
      </div>
    </nav>
  );
}

function TableOfContents({ doc, activeId }: { doc: DocMeta; activeId: string }) {
  const { t } = useTranslation();

  const items = doc.sectionKeys.map((key, index) => ({
    id: `section-${index + 1}`,
    heading: t(key),
  }));

  return (
    <Card className="!p-5">
      <div className="flex items-center gap-2">
        <Toc className="!text-lg text-secondary" />
        <h2 className="font-display text-sm font-semibold uppercase tracking-[0.12em] text-on-surface">
          {t('legal.toc')}
        </h2>
      </div>
      <nav aria-label={t('legal.pageSectionAria')}>
        <ol className="mt-4 space-y-1">
          {items.map((item) => (
            <li key={item.id}>
              <a
                href={`#${item.id}`}
                aria-current={activeId === item.id ? 'true' : undefined}
                className={clsx(
                  'block rounded-lg px-2.5 py-2 text-metadata leading-snug no-underline transition-colors',
                  activeId === item.id
                    ? 'bg-primary-container/25 font-semibold text-primary'
                    : 'text-on-surface-variant hover:bg-surface-container hover:text-on-surface',
                )}
              >
                {item.heading}
              </a>
            </li>
          ))}
        </ol>
      </nav>
    </Card>
  );
}

function DocumentMetaCard({
  doc,
  version,
  lastUpdated,
}: {
  doc: DocMeta;
  version: string;
  lastUpdated: string;
}) {
  const { t } = useTranslation();

  return (
    <Card className="!p-5">
      <h2 className="font-display text-sm font-semibold uppercase tracking-[0.12em] text-on-surface">
        {t('legal.documentMeta')}
      </h2>
      <dl className="mt-4 space-y-3 text-metadata">
        <div className="flex items-center justify-between gap-3">
          <dt className="text-outline">{t('legal.version')}</dt>
          <dd>
            <Chip variant="primary">{version}</Chip>
          </dd>
        </div>
        <div className="flex items-center justify-between gap-3">
          <dt className="text-outline">{t('legal.lastUpdated')}</dt>
          <dd className="font-semibold text-on-surface tabular-nums">{lastUpdated}</dd>
        </div>
        <div className="flex items-center justify-between gap-3">
          <dt className="text-outline">{t('legal.sectionsCount')}</dt>
          <dd className="font-semibold text-on-surface tabular-nums">
            {doc.sectionKeys.length}
          </dd>
        </div>
      </dl>
      <div className="mt-4 border-t border-outline-variant/20 pt-4">
        <p className="text-outline">{t('legal.contact')}</p>
        <a
          href={`mailto:${CONTACT_EMAIL}`}
          className="mt-1 inline-flex items-center gap-1.5 text-body-md font-semibold text-primary no-underline hover:underline"
        >
          <Email className="!text-base" />
          {t('legal.contactEmail')}
        </a>
      </div>
    </Card>
  );
}

type Props = {
  doc: LegalDocId;
  lastUpdated: string;
};

/**
 * Two-column legal portal: sticky table of contents on the left, numbered
 * articles on the right, plus a switcher across the three legal documents.
 * Header and footer come from the shared public shell, and everything resolves
 * through i18n so the portal follows the active locale.
 */
export function LegalDocument({ doc, lastUpdated }: Props) {
  const { t } = useTranslation();
  const meta = findLegalDoc(doc);
  const sectionIds = useMemo(
    () => meta.sectionKeys.map((_, index) => `section-${index + 1}`),
    [meta],
  );
  const activeId = useActiveSection(sectionIds);

  usePageTitle(`legal.${doc}.title`);

  return (
    <PublicShell>
      <DocumentSwitcher doc={meta} />

      <div className="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">
        <div className="mb-6 border-b border-outline-variant/20 pb-6">
          <p className="text-label-sm font-semibold uppercase tracking-[0.14em] text-secondary">
            EnStorage
          </p>
          <p className="sr-only">{t('legal.portalTitle')}</p>
          <p className="mt-2 max-w-2xl text-metadata text-on-surface-variant">
            {t('legal.portalSubtitle')}
          </p>
        </div>
        <div className="grid grid-cols-1 items-start gap-8 lg:grid-cols-[280px_minmax(0,1fr)]">
          <aside className="space-y-4 lg:sticky lg:top-36 lg:max-h-[calc(100vh-9.5rem)] lg:overflow-y-auto lg:overscroll-contain lg:pr-1">
            <TableOfContents doc={meta} activeId={activeId} />
            <DocumentMetaCard
              doc={meta}
              version={t(`legal.${doc}.version`)}
              lastUpdated={lastUpdated}
            />
          </aside>

          <article className="min-w-0 space-y-6">
            <header>
              <Chip variant="warning">{t('legal.complianceBadge')}</Chip>
              <h1 className="mt-4 font-display text-headline-lg text-on-surface">
                {t(`legal.${doc}.title`)}
              </h1>
              <p className="mt-3 max-w-3xl text-body-lg text-on-surface-variant">
                <InlineMarkdown text={t(`legal.${doc}.intro`)} className="whitespace-pre-line" />
              </p>
            </header>

            <Card className="border-l-4 !border-l-primary !p-6">
              <div className="flex items-start gap-3">
                <CardIconBox variant="primary" size="md">
                  <Shield className="!text-2xl" />
                </CardIconBox>
                <div className="min-w-0">
                  <h2 className="font-display text-body-lg font-semibold text-on-surface">
                    {t('legal.summaryTitle')}
                  </h2>
                  <p className="mt-2 text-body-md text-on-surface-variant">
                    <InlineMarkdown text={t(`legal.${doc}.summary`)} className="whitespace-pre-line" />
                  </p>
                </div>
              </div>
            </Card>

            {meta.sectionKeys.map((key, index) => (
              <section key={key} id={`section-${index + 1}`} className="scroll-mt-36">
                <Card className="!p-6">
                  <h2 className="font-display text-body-lg font-semibold text-on-surface">
                    {t(key)}
                  </h2>
                  <div className="mt-3 text-body-md leading-relaxed text-on-surface-variant">
                    <InlineMarkdown text={t(`${key}Body`)} className="whitespace-pre-line" />
                  </div>
                </Card>
              </section>
            ))}

            <Card className="!p-6">
              <div className="flex flex-wrap items-center justify-between gap-4">
                <p className="text-metadata text-outline">
                  {t(`legal.${doc}.title`)} · {t('legal.lastUpdated')}: {lastUpdated}
                </p>
                <Link
                  href="/"
                  className="text-body-md font-semibold text-primary no-underline hover:underline"
                >
                  {t('legal.backHome')}
                </Link>
              </div>
            </Card>
          </article>
        </div>
      </div>
    </PublicShell>
  );
}
