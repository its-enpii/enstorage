'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import {
  ArrowForward,
  Bolt,
  DataObject,
  Hub,
  InsertDriveFile,
  Layers,
  Link as LinkIcon,
  Search,
  Shield,
  Speed,
  Terminal,
  VpnKey,
} from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/Button';
import { Card, CardIconBox } from '@/components/Card';
import { Chip } from '@/components/Chip';
import { CodeBlock } from '@/components/CodeBlock';
import { InlineMarkdown } from '@/components/InlineMarkdown';
import { Input } from '@/components/Input';
import { MultiLangSnippet, SNIPPET_LANGS, type SnippetLang } from '@/components/MultiLangSnippet';
import { PublicShell } from '@/components/PublicShell';
import { usePageTitle } from '@/lib/usePageTitle';
import { prefersReducedMotion, scrollToId } from '@/lib/site';
import {
  API_PREFIX,
  AUTH_SAMPLES,
  BASE_URLS,
  ENVELOPE_SAMPLES,
  ERROR_CODES,
  OPENAPI_URLS,
  REFERENCE,
  REFERENCE_COUNT,
  REFERENCE_GROUPS,
  SCOPE_ORDER,
  SNIPPETS,
  TOTAL_ENDPOINTS,
  type ApiScope,
} from '@/lib/apiCatalog';
import { EndpointReference } from './EndpointReference';

/** Keeps anchor targets clear of the sticky header. */
const SCROLL_MARGIN = 'scroll-mt-24';

const SECTION_IDS = [
  'start',
  'auth',
  'scopes',
  'envelope',
  'errors',
  'reference',
  'rate-limit',
  'openapi',
] as const;

type SectionId = (typeof SECTION_IDS)[number];

const SECTIONS: Array<{ id: SectionId; icon: typeof Terminal }> = [
  { id: 'start', icon: Terminal },
  { id: 'auth', icon: VpnKey },
  { id: 'scopes', icon: Shield },
  { id: 'envelope', icon: DataObject },
  { id: 'errors', icon: Bolt },
  { id: 'reference', icon: InsertDriveFile },
  { id: 'rate-limit', icon: Speed },
  { id: 'openapi', icon: Hub },
];

const SCOPE_CHIP: Record<ApiScope, 'success' | 'primary' | 'danger' | 'warning' | 'default'> = {
  read: 'success',
  write: 'primary',
  delete: 'danger',
  full: 'warning',
  sanctum: 'default',
  public: 'default',
};

/** Shared preference so every snippet on the page speaks the same language. */
const LANG_STORAGE_KEY = 'enstorage.docs.lang';

function readStoredLang(): SnippetLang {
  if (typeof window === 'undefined') return 'curl';
  const stored = window.localStorage.getItem(LANG_STORAGE_KEY);
  return SNIPPET_LANGS.includes(stored as SnippetLang) ? (stored as SnippetLang) : 'curl';
}

/** Highlights the TOC entry whose section is closest to the top of the view. */
function useActiveSection(ids: readonly string[]) {
  const [active, setActive] = useState<string>(ids[0] ?? '');

  useEffect(() => {
    const visible = new Map<string, number>();
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          const id = (entry.target as HTMLElement).id;
          if (entry.isIntersecting) visible.set(id, entry.intersectionRatio);
          else visible.delete(id);
        }
        const first = ids.find((id) => visible.has(id));
        if (first) setActive(first);
      },
      { rootMargin: '-96px 0px -60% 0px', threshold: [0, 0.25, 0.5, 1] },
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

function SectionHeading({
  eyebrowKey,
  titleKey,
  subtitleKey,
}: {
  eyebrowKey: string;
  titleKey: string;
  subtitleKey?: string;
}) {
  const { t } = useTranslation();
  return (
    <div className="mb-6 max-w-2xl">
      <p className="text-label-sm font-semibold uppercase tracking-[0.14em] text-secondary">
        {t(eyebrowKey)}
      </p>
      <h2 className="mt-2 font-display text-headline-lg text-on-surface">{t(titleKey)}</h2>
      {subtitleKey && (
        <p className="mt-2 text-body-md leading-relaxed text-on-surface-variant">
          <InlineMarkdown text={t(subtitleKey)} />
        </p>
      )}
    </div>
  );
}

function DocsSection({
  id,
  eyebrowKey,
  titleKey,
  subtitleKey,
  children,
}: {
  id: SectionId;
  eyebrowKey: string;
  titleKey: string;
  subtitleKey?: string;
  children: React.ReactNode;
}) {
  return (
    <section
      id={id}
      className={clsx('border-b border-outline-variant/20 py-10 first:pt-4 sm:py-12', SCROLL_MARGIN)}
    >
      <div className="min-w-0">
        <SectionHeading eyebrowKey={eyebrowKey} titleKey={titleKey} subtitleKey={subtitleKey} />
        {children}
      </div>
    </section>
  );
}

function ScopeChip({ scope }: { scope: ApiScope }) {
  const { t } = useTranslation();
  return <Chip variant={SCOPE_CHIP[scope]}>{t(`docs.scopes.short.${scope}`)}</Chip>;
}

/* ================================== sidebar ================================= */

function DocsToc({
  activeId,
  query,
  onQueryChange,
  onClear,
  lang,
  onLangChange,
}: {
  activeId: string;
  query: string;
  onQueryChange: (value: string) => void;
  onClear: () => void;
  lang: SnippetLang;
  onLangChange: (value: SnippetLang) => void;
}) {
  const { t } = useTranslation();

  /** The sidebar search box drives the reference explorer, so it reports its own matches. */
  const matchCount = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return REFERENCE_COUNT;
    return REFERENCE.filter((entry) =>
      `${entry.group} ${entry.method} ${entry.path} ${entry.key} ${t(
        `docs.ref.items.${entry.key}.title`,
      )} ${t(`docs.ref.items.${entry.key}.body`)}`
        .toLowerCase()
        .includes(needle),
    ).length;
  }, [query, t]);

  return (
    <Card className="!p-5">
      <div className="flex items-center gap-2">
        <Terminal className="!text-lg text-secondary" />
        <h2 className="font-display text-sm font-semibold uppercase tracking-[0.12em] text-on-surface">
          {t('docs.toc')}
        </h2>
      </div>

      <div className="relative mt-4">
        <Input
          type="search"
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          placeholder={t('docs.searchPlaceholder')}
          aria-label={t('docs.searchAria')}
          leftIcon={<Search className="!text-lg" />}
          className="!h-11 !rounded-xl !text-body-md"
        />
        {query.trim().length > 0 && (
          <div className="mt-2 flex items-center justify-between gap-2">
            <p className="text-metadata text-outline tabular-nums">
              {t('docs.searchResults', { count: matchCount })}
            </p>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              onClick={onClear}
              className="!h-7 shrink-0 !px-2 !text-metadata !text-outline hover:!text-on-surface"
            >
              {t('docs.searchReset')}
            </Button>
          </div>
        )}
      </div>

      <nav aria-label={t('docs.tocAria')} className="mt-4">
        <ol className="space-y-1">
          {SECTIONS.map((section) => {
            const Icon = section.icon;
            const active = activeId === section.id;
            return (
              <li key={section.id}>
                <a
                  href={`#${section.id}`}
                  aria-current={active ? 'true' : undefined}
                  className={clsx(
                    'flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-metadata font-semibold leading-snug no-underline transition-colors',
                    active
                      ? 'bg-primary-container/25 text-primary'
                      : 'text-on-surface-variant hover:bg-surface-container hover:text-on-surface',
                  )}
                >
                  <Icon className="!text-base shrink-0" />
                  {t(`docs.nav.${section.id}`)}
                </a>
                {section.id === 'reference' && (
                  <ul className="mb-1 ml-6 space-y-0.5 border-l border-outline-variant/20 pl-2">
                    {REFERENCE_GROUPS.map((group) => (
                      <li key={group.id}>
                        <a
                          href={`#ref-${group.id}`}
                          className="block truncate rounded-md px-2 py-1 text-metadata text-on-surface-variant no-underline transition-colors hover:bg-surface-container hover:text-on-surface"
                        >
                          {t(`docs.ref.groups.${group.key}.title`)}
                        </a>
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            );
          })}
        </ol>
      </nav>

      <div className="mt-5 border-t border-outline-variant/20 pt-4">
        <p className="text-metadata font-semibold uppercase tracking-wider text-outline">
          {t('docs.lang.label')}
        </p>
        <div className="mt-2 flex flex-wrap gap-1" role="group" aria-label={t('docs.lang.aria')}>
          {SNIPPET_LANGS.map((option) => (
            <Button
              key={option}
              type="button"
              size="sm"
              variant={lang === option ? 'primary' : 'secondary'}
              aria-pressed={lang === option}
              onClick={() => onLangChange(option)}
              className="!h-7 rounded-full !px-2.5 !text-metadata"
            >
              {t(`docs.lang.short.${option}`)}
            </Button>
          ))}
        </div>
      </div>

      <div className="mt-5 border-t border-outline-variant/20 pt-4">
        <dl className="space-y-2 text-metadata">
          <div className="flex items-center justify-between gap-3">
            <dt className="text-outline">{t('docs.meta.version')}</dt>
            <dd>
              <Chip variant="primary">{t('docs.meta.versionValue')}</Chip>
            </dd>
          </div>
          <div className="flex items-center justify-between gap-3">
            <dt className="text-outline">{t('docs.meta.endpoints')}</dt>
            <dd className="font-semibold text-on-surface tabular-nums">{TOTAL_ENDPOINTS}</dd>
          </div>
          <div className="flex items-center justify-between gap-3">
            <dt className="text-outline">{t('docs.meta.reference')}</dt>
            <dd className="font-semibold text-on-surface tabular-nums">{REFERENCE_COUNT}</dd>
          </div>
          <div className="flex items-center justify-between gap-3">
            <dt className="text-outline">{t('docs.meta.prefix')}</dt>
            <dd className="font-mono font-semibold text-on-surface">{API_PREFIX}</dd>
          </div>
        </dl>
      </div>
    </Card>
  );
}

/* ================================== content ================================= */

function QuickStart({ lang, onLangChange }: { lang: SnippetLang; onLangChange: (value: SnippetLang) => void }) {
  const { t } = useTranslation();
  const steps = ['prepare', 'upload', 'summary', 'share'] as const;

  return (
    <DocsSection
      id="start"
      eyebrowKey="docs.start.eyebrow"
      titleKey="docs.start.title"
      subtitleKey="docs.start.subtitle"
    >
      <Card className="mb-4 !p-5">
        <h3 className="font-display text-body-md font-semibold text-on-surface">
          {t('docs.baseUrlTitle')}
        </h3>
        <ul className="mt-3 space-y-2">
          {BASE_URLS.map((entry) => (
            <li
              key={entry.key}
              className="flex flex-wrap items-center justify-between gap-2 border-b border-outline-variant/20 pb-2 last:border-b-0 last:pb-0"
            >
              <span className="text-metadata uppercase tracking-wider text-outline">
                {t(`docs.baseUrl.${entry.key}`)}
              </span>
              <code className="rounded bg-surface-container-high px-1.5 py-0.5 font-mono text-primary text-xs">
                {entry.url}
              </code>
            </li>
          ))}
        </ul>
      </Card>

      <ol className="space-y-4">
        {steps.map((step, index) => {
          const snippet = SNIPPETS.find((item) => item.id === step);
          if (!snippet) return null;
          return (
            <li key={step} className="flex flex-col gap-3">
              <div className="flex items-center gap-3">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary-container text-metadata font-bold text-on-primary-container tabular-nums">
                  {index + 1}
                </span>
                <div className="min-w-0">
                  <h3 className="font-display text-body-md font-semibold text-on-surface">
                    {t(`docs.snippets.${snippet.key}.title`)}
                  </h3>
                  <p className="text-metadata text-on-surface-variant">
                    <InlineMarkdown text={t(`docs.snippets.${snippet.key}.body`)} />
                  </p>
                </div>
              </div>
              {snippet.call ? (
                <MultiLangSnippet
                  call={snippet.call}
                  lang={lang}
                  onLangChange={onLangChange}
                  label={t(`docs.snippets.${snippet.key}.title`)}
                />
              ) : (
                <CodeBlock code={snippet.code} file={snippet.file} />
              )}
            </li>
          );
        })}
      </ol>
    </DocsSection>
  );
}

function Authentication() {
  const { t } = useTranslation();

  const modes = [
    { key: 'apiKey', code: AUTH_SAMPLES.bearer, noteKey: 'apiKeyNote' },
    { key: 'header', code: AUTH_SAMPLES.header, noteKey: 'headerNote' },
    { key: 'sanctum', code: AUTH_SAMPLES.sanctum, noteKey: 'sanctumNote' },
  ] as const;

  return (
    <DocsSection
      id="auth"
      eyebrowKey="docs.auth.eyebrow"
      titleKey="docs.auth.title"
      subtitleKey="docs.auth.subtitle"
    >
      <div className="grid gap-4 xl:grid-cols-2">
        {modes.map((mode) => (
          <div key={mode.key}>
            <h3 className="font-display text-body-md font-semibold text-on-surface">
              {t(`docs.auth.modes.${mode.key}`)}
            </h3>
            <p className="mt-1 mb-3 text-metadata leading-relaxed text-on-surface-variant">
              <InlineMarkdown text={t(`docs.auth.${mode.noteKey}`)} />
            </p>
            <CodeBlock code={mode.code} file={`${mode.key}.http`} maxHeightClass="max-h-52" />
          </div>
        ))}
      </div>

      <Card className="mt-4 !p-5">
        <div className="flex items-center gap-3">
          <CardIconBox variant="muted" size="md">
            <VpnKey className="!text-2xl" />
          </CardIconBox>
          <div className="min-w-0">
            <h3 className="font-display text-body-md font-semibold text-on-surface">
              {t('docs.auth.keyFormatTitle')}
            </h3>
            <p className="text-metadata text-on-surface-variant">
              <InlineMarkdown text={t('docs.auth.keyFormatBody')} />
            </p>
          </div>
        </div>
        <div className="mt-4 grid gap-2 sm:grid-cols-2">
          <p className="flex items-center justify-between gap-3 rounded-xl bg-surface-container px-3 py-2 text-metadata">
            <span className="uppercase tracking-wider text-outline">{t('docs.auth.header')}</span>
            <code className="rounded bg-surface-container-high px-1.5 py-0.5 font-mono text-primary text-xs">
              Authorization
            </code>
          </p>
          <p className="flex items-center justify-between gap-3 rounded-xl bg-surface-container px-3 py-2 text-metadata">
            <span className="uppercase tracking-wider text-outline">{t('docs.auth.prefix')}</span>
            <code className="rounded bg-surface-container-high px-1.5 py-0.5 font-mono text-primary text-xs">
              Bearer
            </code>
          </p>
        </div>
        <p className="mt-4 border-t border-outline-variant/20 pt-4 text-metadata leading-relaxed text-outline">
          <InlineMarkdown text={t('docs.auth.sanctumOnlyWarning')} />
        </p>
      </Card>
    </DocsSection>
  );
}

function Scopes() {
  const { t } = useTranslation();

  return (
    <DocsSection
      id="scopes"
      eyebrowKey="docs.scopes.eyebrow"
      titleKey="docs.scopes.title"
      subtitleKey="docs.scopes.subtitle"
    >
      <Card className="!p-0">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] border-collapse text-left">
            <caption className="sr-only">{t('docs.scopes.tableAria')}</caption>
            <thead>
              <tr className="bg-surface-container/60">
                {['scope', 'allows', 'example'].map((col) => (
                  <th
                    key={col}
                    scope="col"
                    className="px-5 py-2.5 text-metadata font-semibold uppercase tracking-wider text-outline"
                  >
                    {t(`docs.scopes.columns.${col}`)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {SCOPE_ORDER.map((scope) => (
                <tr key={scope} className="border-t border-outline-variant/20 align-top">
                  <td className="px-5 py-3">
                    <ScopeChip scope={scope} />
                  </td>
                  <td className="px-5 py-3 text-metadata leading-relaxed text-on-surface-variant">
                    <InlineMarkdown text={t(`docs.scopes.allows.${scope}`)} />
                  </td>
                  <td className="px-5 py-3">
                    <code className="rounded bg-surface-container-high px-1.5 py-0.5 font-mono text-primary text-xs">
                      {t(`docs.scopes.example.${scope}`)}
                    </code>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      <div className="mt-4 grid gap-4 lg:grid-cols-2">
        <Card className="!p-5">
          <div className="flex items-center gap-3">
            <CardIconBox variant="gold" size="md">
              <Speed className="!text-2xl" />
            </CardIconBox>
            <div className="min-w-0">
              <h3 className="font-display text-body-md font-semibold text-on-surface">
                {t('docs.rateLimit.title')}
              </h3>
              <p className="text-metadata text-on-surface-variant">
                <InlineMarkdown text={t('docs.rateLimit.subtitle')} />
              </p>
            </div>
          </div>
          <dl className="mt-4 space-y-2 text-metadata">
            {['perKey', 'burst', 'response'].map((row) => (
              <div
                key={row}
                className="flex flex-wrap items-baseline justify-between gap-2 border-b border-outline-variant/20 pb-2 last:border-b-0 last:pb-0"
              >
                <dt className="uppercase tracking-wider text-outline">
                  {t(`docs.rateLimit.${row}Label`)}
                </dt>
                <dd className="text-right font-mono text-on-surface">
                  <InlineMarkdown text={t(`docs.rateLimit.${row}`)} />
                </dd>
              </div>
            ))}
          </dl>
        </Card>

        <Card className="!p-5">
          <div className="flex items-center gap-3">
            <CardIconBox variant="primary" size="md">
              <LinkIcon className="!text-2xl" />
            </CardIconBox>
            <div className="min-w-0">
              <h3 className="font-display text-body-md font-semibold text-on-surface">
                {t('docs.shareLinks.title')}
              </h3>
              <p className="mt-1 text-metadata leading-relaxed text-on-surface-variant">
                <InlineMarkdown text={t('docs.shareLinks.subtitle')} />
              </p>
            </div>
          </div>
          <ul className="mt-4 space-y-2 text-metadata leading-relaxed text-on-surface-variant">
            {['public', 'expiry', 'views'].map((row) => (
              <li key={row}>
                <InlineMarkdown text={t(`docs.shareLinks.${row}`)} />
              </li>
            ))}
          </ul>
          <Link
            href="/legal/security"
            className="mt-4 inline-flex items-center gap-1.5 border-t border-outline-variant/20 pt-4 text-metadata font-semibold text-primary no-underline hover:underline"
          >
            {t('docs.shareLinks.securityLink')}
            <ArrowForward className="!text-base" />
          </Link>
        </Card>
      </div>
    </DocsSection>
  );
}

function Reference({ lang, onLangChange, query }: { lang: SnippetLang; onLangChange: (value: SnippetLang) => void; query: string }) {
  const { t } = useTranslation();

  return (
    <DocsSection
      id="reference"
      eyebrowKey="docs.ref.eyebrow"
      titleKey="docs.ref.title"
      subtitleKey="docs.ref.subtitle"
    >
      <EndpointReference lang={lang} onLangChange={onLangChange} query={query} />
    </DocsSection>
  );
}

function ResponseFormat() {
  const { t } = useTranslation();

  return (
    <DocsSection
      id="envelope"
      eyebrowKey="docs.envelope.eyebrow"
      titleKey="docs.envelope.title"
      subtitleKey="docs.envelope.subtitle"
    >
      <div className="grid gap-4 xl:grid-cols-2">
        <div>
          <h3 className="mb-2 font-display text-body-md font-semibold text-on-surface">
            {t('docs.envelope.success')}
          </h3>
          <CodeBlock code={ENVELOPE_SAMPLES.success} file="200.json" maxHeightClass="max-h-72" />
        </div>
        <div>
          <h3 className="mb-2 font-display text-body-md font-semibold text-on-surface">
            {t('docs.envelope.error')}
          </h3>
          <CodeBlock code={ENVELOPE_SAMPLES.error} file="422.json" maxHeightClass="max-h-72" />
        </div>
      </div>
      <p className="mt-4 text-metadata leading-relaxed text-outline">
        <InlineMarkdown text={t('docs.envelope.note')} />
      </p>
    </DocsSection>
  );
}

function ErrorCodes() {
  const { t } = useTranslation();

  return (
    <DocsSection
      id="errors"
      eyebrowKey="docs.errors.eyebrow"
      titleKey="docs.errors.title"
      subtitleKey="docs.errors.subtitle"
    >
      <Card className="!p-0">
        <div className="grid gap-px bg-outline-variant/20 sm:grid-cols-2 lg:grid-cols-3">
          {ERROR_CODES.map((row) => (
            <div key={row.status} className="bg-surface px-5 py-3">
              <p className="flex items-baseline gap-2">
                <code
                  className={clsx(
                    'rounded px-1.5 py-0.5 font-mono text-xs font-bold',
                    Number(row.status) >= 400
                      ? 'bg-error-container/30 text-error'
                      : 'bg-primary-container/30 text-primary',
                  )}
                >
                  {row.status}
                </code>
                <span className="text-metadata font-semibold uppercase tracking-wider text-on-surface">
                  {t(`docs.errors.meaning.${row.key}`)}
                </span>
              </p>
              <p className="mt-1 text-metadata leading-relaxed text-on-surface-variant">
                <InlineMarkdown text={t(`docs.errors.${row.key}`)} />
              </p>
            </div>
          ))}
        </div>
      </Card>
    </DocsSection>
  );
}

function RateLimiting() {
  const { t } = useTranslation();
  const rows = ['apiKey', 'sanctum', 'auth'] as const;

  return (
    <DocsSection
      id="rate-limit"
      eyebrowKey="docs.rateLimit.eyebrow"
      titleKey="docs.rateLimit.pageTitle"
      subtitleKey="docs.rateLimit.pageSubtitle"
    >
      <Card className="!p-0">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[560px] border-collapse text-left">
            <caption className="sr-only">{t('docs.rateLimit.pageTitle')}</caption>
            <thead>
              <tr className="bg-surface-container/60">
                {['mode', 'limit'].map((col) => (
                  <th
                    key={col}
                    scope="col"
                    className="px-5 py-2.5 text-metadata font-semibold uppercase tracking-wider text-outline"
                  >
                    {t(`docs.rateLimit.columns.${col}`)}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row} className="border-t border-outline-variant/20">
                  <td className="px-5 py-2.5 text-metadata font-semibold text-on-surface">
                    {t(`docs.rateLimit.modes.${row}`)}
                  </td>
                  <td className="px-5 py-2.5 text-metadata text-on-surface-variant">
                    <InlineMarkdown text={t(`docs.rateLimit.limits.${row}`)} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>
      <p className="mt-4 text-metadata leading-relaxed text-outline">
        <InlineMarkdown text={t('docs.rateLimit.retryNote')} />
      </p>
    </DocsSection>
  );
}

function OpenApi() {
  const { t } = useTranslation();

  return (
    <DocsSection
      id="openapi"
      eyebrowKey="docs.openapi.eyebrow"
      titleKey="docs.openapi.title"
      subtitleKey="docs.openapi.subtitle"
    >
      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="!p-5">
          <h3 className="font-display text-body-md font-semibold text-on-surface">
            {t('docs.openapi.swaggerTitle')}
          </h3>
          <p className="mt-1 text-metadata text-on-surface-variant">{t('docs.openapi.swaggerBody')}</p>
          <code className="mt-3 block break-all rounded-xl bg-surface-container-lowest px-3 py-2 font-mono text-metadata text-primary">
            {OPENAPI_URLS.swagger}
          </code>
        </Card>
        <Card className="!p-5">
          <h3 className="font-display text-body-md font-semibold text-on-surface">
            {t('docs.openapi.specTitle')}
          </h3>
          <p className="mt-1 text-metadata text-on-surface-variant">{t('docs.openapi.specBody')}</p>
          <code className="mt-3 block break-all rounded-xl bg-surface-container-lowest px-3 py-2 font-mono text-metadata text-primary">
            {OPENAPI_URLS.spec}
          </code>
        </Card>
      </div>
      <div className="mt-6 flex flex-wrap items-center gap-3">
        <Link
          href="/api-keys"
          className="inline-flex items-center gap-2 rounded-xl bg-primary-container px-4 py-2.5 text-sm font-semibold text-on-primary-container no-underline transition-colors hover:bg-primary-container/80"
        >
          <VpnKey className="!text-lg" />
          {t('docs.cta.createKey')}
        </Link>
        <Link
          href="/"
          className="inline-flex items-center gap-2 text-body-md font-semibold text-on-surface-variant no-underline hover:text-on-surface"
        >
          <Layers className="!text-lg" />
          {t('docs.cta.backHome')}
          <ArrowForward className="!text-lg" />
        </Link>
      </div>
    </DocsSection>
  );
}

/* =================================== page ================================== */

export default function DocsClient() {
  usePageTitle('docs.pageTitle');
  const { t } = useTranslation();
  const activeId = useActiveSection(SECTION_IDS);
  const [query, setQuery] = useState('');
  const [lang, setLang] = useState<SnippetLang>('curl');

  useEffect(() => {
    setLang(readStoredLang());
  }, []);

  function changeLang(value: SnippetLang) {
    setLang(value);
    if (typeof window !== 'undefined') window.localStorage.setItem(LANG_STORAGE_KEY, value);
  }

  useEffect(() => {
    const root = document.documentElement;
    const previous = root.style.scrollBehavior;
    if (!prefersReducedMotion()) root.style.scrollBehavior = 'smooth';
    return () => {
      root.style.scrollBehavior = previous;
    };
  }, []);

  // Deep links like /#api-style section jumps from the header nav.
  useEffect(() => {
    const hash = decodeURIComponent(window.location.hash.replace('#', ''));
    if (hash && (SECTION_IDS as readonly string[]).includes(hash)) {
      window.setTimeout(() => scrollToId(hash), 60);
    }
  }, []);

  return (
    <PublicShell>
      <div className="mx-auto w-full max-w-6xl px-4 py-8 sm:px-6">
        <header className="mb-6 border-b border-outline-variant/20 pb-6">
          <p className="text-label-sm font-semibold uppercase tracking-[0.14em] text-secondary">
            {t('docs.eyebrow')}
          </p>
          <h1 className="mt-2 font-display text-headline-lg text-on-surface">{t('docs.title')}</h1>
          <p className="mt-3 max-w-3xl text-body-md leading-relaxed text-on-surface-variant">
            <InlineMarkdown text={t('docs.subtitle')} />
          </p>
          <div className="mt-4 flex flex-wrap items-center gap-2">
            <Chip variant="primary">{t('docs.badge.version')}</Chip>
            <Chip variant="success">{t('docs.badge.endpoints', { count: TOTAL_ENDPOINTS })}</Chip>
            <Chip variant="warning">{t('docs.badge.prefix', { prefix: API_PREFIX })}</Chip>
          </div>
        </header>

        <div className="grid grid-cols-1 items-start gap-8 lg:grid-cols-[280px_minmax(0,1fr)]">
          <aside className="space-y-4 lg:sticky lg:top-28 lg:max-h-[calc(100vh-7.5rem)] lg:overflow-y-auto lg:overscroll-contain lg:pr-1">
            <DocsToc
              activeId={activeId}
              query={query}
              onQueryChange={setQuery}
              onClear={() => setQuery('')}
              lang={lang}
              onLangChange={changeLang}
            />
          </aside>

          <div className="min-w-0 space-y-12">
            <QuickStart lang={lang} onLangChange={changeLang} />
            <Authentication />
            <Scopes />
            <ResponseFormat />
            <ErrorCodes />
            <Reference lang={lang} onLangChange={changeLang} query={query} />
            <RateLimiting />
            <OpenApi />
          </div>
        </div>
      </div>
    </PublicShell>
  );
}
