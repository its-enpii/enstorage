'use client';

import { useMemo, useState } from 'react';
import {
  ExpandLess,
  ExpandMore,
  Folder,
  InsertDriveFile,
  KeyboardArrowRight,
  Layers,
  Link as LinkIcon,
  Notifications,
  Search,
  VpnKey,
  Warning,
} from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/Button';
import { CardIconBox } from '@/components/Card';
import { Chip } from '@/components/Chip';
import { CodeBlock } from '@/components/CodeBlock';
import { InlineMarkdown } from '@/components/InlineMarkdown';
import { MultiLangSnippet, type SnippetLang } from '@/components/MultiLangSnippet';
import {
  API_PREFIX,
  REFERENCE_SECTIONS,
  type ApiScope,
  type ParamRow,
  type ReferenceEntry,
} from '@/lib/apiCatalog';

const SCOPE_CHIP: Record<ApiScope, 'success' | 'primary' | 'danger' | 'warning' | 'default'> = {
  read: 'success',
  write: 'primary',
  delete: 'danger',
  full: 'warning',
  sanctum: 'default',
  public: 'default',
};

const GROUP_ICONS: Record<string, typeof InsertDriveFile> = {
  auth: VpnKey,
  files: InsertDriveFile,
  folders: Folder,
  storage: Layers,
  share: LinkIcon,
  discovery: Search,
  webhooks: Notifications,
  notifications: Notifications,
};

const METHOD_BADGE_CLASS: Record<ReferenceEntry['method'], string> = {
  GET: 'bg-sky-500/15 text-sky-400 border border-sky-500/30',
  POST: 'bg-emerald-500/15 text-emerald-400 border border-emerald-500/30',
  PUT: 'bg-amber-500/15 text-amber-400 border border-amber-500/30',
  PATCH: 'bg-amber-500/15 text-amber-400 border border-amber-500/30',
  DELETE: 'bg-rose-500/15 text-rose-400 border border-rose-500/30',
};

/** Which parameter tables a card renders, in reading order. */
const PARAM_TABLES = [
  { field: 'headerRows', label: 'headers' },
  { field: 'pathRows', label: 'path' },
  { field: 'queryRows', label: 'query' },
  { field: 'bodyRows', label: 'body' },
] as const;

function ParamTable({ rows, caption }: { rows: ParamRow[]; caption: string }) {
  const { t } = useTranslation();
  const columns = ['name', 'type', 'required', 'description'] as const;

  return (
    <div className="overflow-x-auto rounded-xl border border-outline-variant/20">
      <table className="w-full min-w-[540px] border-collapse text-left">
        <caption className="sr-only">{caption}</caption>
        <thead>
          <tr className="bg-surface-container/60">
            {columns.map((column) => (
              <th
                key={column}
                scope="col"
                className="px-3 py-2 text-metadata font-semibold uppercase tracking-wider text-outline"
              >
                {t(`docs.ref.columns.${column}`)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={`${caption}-${row.name}`} className="border-t border-outline-variant/20 align-top">
              <td className="px-3 py-2 font-mono text-metadata font-semibold text-on-surface">
                {row.name}
              </td>
              <td className="px-3 py-2 font-mono text-metadata text-on-surface-variant">{row.type}</td>
              <td className="px-3 py-2 text-metadata">
                {row.required ? (
                  <span className="font-semibold text-primary">{t('docs.ref.required')}</span>
                ) : (
                  <span className="text-outline">{t('docs.ref.optional')}</span>
                )}
              </td>
              <td className="px-3 py-2 text-metadata leading-relaxed text-on-surface-variant">
                <InlineMarkdown text={t(`docs.p.${row.key}`)} />
                {row.default !== undefined && (
                  <span className="ml-1 text-outline">
                    · {t('docs.ref.default')}{' '}
                    <code className="rounded bg-surface-container-high px-1 py-0.5 font-mono text-primary text-xs">
                      {row.default === '' ? '""' : row.default}
                    </code>
                  </span>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function FieldLabel({ text }: { text: string }) {
  return (
    <p className="mb-1.5 flex items-center gap-1.5 text-metadata font-semibold uppercase tracking-wider text-outline">
      <KeyboardArrowRight className="!text-base text-secondary" />
      {text}
    </p>
  );
}

function EndpointCard({
  entry,
  lang,
  onLangChange,
  open,
  onToggle,
  isFirst,
  isLast,
}: {
  entry: ReferenceEntry;
  lang: SnippetLang;
  onLangChange: (value: SnippetLang) => void;
  open: boolean;
  onToggle: () => void;
  isFirst: boolean;
  isLast: boolean;
}) {
  const { t } = useTranslation();
  const title = t(`docs.ref.items.${entry.key}.title`);

  return (
    <div id={`card-${entry.id}`} className="scroll-mt-24">
      <Button
        type="button"
        variant="ghost"
        onClick={onToggle}
        aria-expanded={open}
        aria-controls={`card-${entry.id}-body`}
        aria-label={`${entry.method} ${entry.path} — ${title}`}
        className={clsx(
          '!h-auto w-full !justify-between items-center gap-3 !px-4 !py-2.5 text-left transition-colors hover:bg-surface-container/30 sm:!px-5',
          isFirst && 'rounded-t-2xl',
          isLast && !open && 'rounded-b-2xl',
        )}
      >
        <span className="flex min-w-0 flex-1 flex-wrap items-center gap-2.5">
          <code
            className={clsx(
              'shrink-0 rounded-md px-2 py-0.5 font-mono text-xs font-bold uppercase tracking-wider',
              METHOD_BADGE_CLASS[entry.method],
            )}
          >
            {entry.method}
          </code>
          <code className="break-all font-mono text-metadata font-semibold text-on-surface">
            {API_PREFIX}
            {entry.path}
          </code>
          <Chip variant={SCOPE_CHIP[entry.scope]}>{t(`docs.scopes.short.${entry.scope}`)}</Chip>
          {entry.flag === 'ownerOnly' && <Chip variant="warning">{t('docs.ownerOnly')}</Chip>}
        </span>
        <span className="flex shrink-0 items-center gap-2 text-right">
          <span className="font-display text-body-md font-semibold text-on-surface">
            {title}
          </span>
          <span className="flex items-center text-outline">
            {open ? <ExpandLess className="!text-lg" /> : <ExpandMore className="!text-lg" />}
          </span>
        </span>
      </Button>

      {open && (
        <div
          id={`card-${entry.id}-body`}
          className={clsx(
            'space-y-6 border-t border-outline-variant/15 bg-surface-container-low/30 p-5 sm:p-6',
            isLast && 'rounded-b-2xl',
          )}
        >
          <p className="text-metadata leading-relaxed text-on-surface-variant">
            <InlineMarkdown text={t(`docs.ref.items.${entry.key}.body`)} />
          </p>

          {PARAM_TABLES.map(({ field, label }) => {
            const rows = entry[field];
            if (!rows?.length) return null;
            return (
              <div key={field}>
                <FieldLabel text={t(`docs.ref.tables.${label}`)} />
                <ParamTable rows={rows} caption={`${entry.id} ${label}`} />
              </div>
            );
          })}

          <div>
            <FieldLabel text={t('docs.ref.tables.request')} />
            <MultiLangSnippet
              call={entry.call}
              lang={lang}
              onLangChange={onLangChange}
              label={`${entry.method} ${entry.path}`}
            />
          </div>

          <div>
            <FieldLabel text={t('docs.ref.tables.response')} />
            <CodeBlock
              code={entry.response.code}
              lang={entry.response.lang ?? 'json'}
              file={`${entry.response.status}.json`}
              labelKey="docs.responseAria"
              maxHeightClass="max-h-80"
            />
          </div>

          {(entry.errors?.length || entry.noteKey) && (
            <div className="flex flex-wrap items-start gap-x-5 gap-y-2 border-t border-outline-variant/20 pt-3">
              {entry.errors?.length ? (
                <p className="flex flex-wrap items-center gap-1.5">
                  <span className="text-metadata font-semibold uppercase tracking-wider text-outline">
                    {t('docs.ref.errorsLabel')}
                  </span>
                  {entry.errors.map((status) => (
                    <Chip key={status} variant={Number(status) >= 400 ? 'danger' : 'default'}>
                      {status}
                    </Chip>
                  ))}
                </p>
              ) : null}
              {entry.noteKey ? (
                <p className="flex min-w-0 flex-1 items-start gap-1.5 text-metadata leading-relaxed text-outline">
                  <Warning className="!text-base mt-px shrink-0 text-secondary" />
                  <span>
                    <InlineMarkdown text={t(`docs.ref.notes.${entry.noteKey}`)} />
                  </span>
                </p>
              ) : null}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

/**
 * The deep endpoint reference: one collapsible card per request, each with
 * parameter tables, multi-language samples, and a response example.
 */
export function EndpointReference({
  lang,
  onLangChange,
  query,
  onResetQuery,
}: {
  lang: SnippetLang;
  onLangChange: (value: SnippetLang) => void;
  query: string;
  onResetQuery: () => void;
}) {
  const { t } = useTranslation();
  const [expanded, setExpanded] = useState<Record<string, boolean>>({});
  const needle = query.trim().toLowerCase();

  const matching = useMemo(
    () =>
      REFERENCE_SECTIONS.map((section) => ({
        section,
        items: section.items.filter((entry) =>
          `${entry.group} ${entry.method} ${entry.path} ${entry.key} ${t(
            `docs.ref.items.${entry.key}.title`,
          )} ${t(`docs.ref.items.${entry.key}.body`)}`
            .toLowerCase()
            .includes(needle),
        ),
      })),
    [needle, t],
  );

  const sections = useMemo(
    () => matching.filter((group) => group.items.length > 0),
    [matching],
  );

  const ids = sections.flatMap((entry) => entry.items.map((item) => item.id));
  const matchCount = ids.length;
  const allOpen = ids.length > 0 && ids.every((id) => Boolean(expanded[id]));

  function toggle(id: string) {
    setExpanded((prev) => ({ ...prev, [id]: !prev[id] }));
  }

  function setAll(open: boolean) {
    setExpanded(Object.fromEntries(ids.map((id) => [id, open])));
  }

  if (matchCount === 0) {
    return (
      <div className="rounded-2xl border border-outline-variant/20 bg-surface-container-lowest p-6 shadow-ambient">
        <p className="text-body-md text-on-surface-variant">{t('docs.searchEmpty')}</p>
        <Button type="button" variant="secondary" size="sm" className="mt-3" onClick={onResetQuery}>
          {t('docs.searchReset')}
        </Button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-surface-container px-4 py-3">
        <p className="text-metadata text-on-surface-variant">
          <InlineMarkdown text={t('docs.ref.legend', { count: matchCount })} />
        </p>
        <Button
          type="button"
          size="sm"
          variant="secondary"
          onClick={() => setAll(!allOpen)}
          leftIcon={allOpen ? <ExpandLess className="!text-lg" /> : <ExpandMore className="!text-lg" />}
          className="!h-8 shrink-0 rounded-full !px-3 !text-metadata"
        >
          {allOpen ? t('docs.ref.collapseAll') : t('docs.ref.expandAll')}
        </Button>
      </div>

      {sections.map(({ section, items }) => {
        const Icon = GROUP_ICONS[section.key] ?? InsertDriveFile;
        return (
          <section key={section.id} id={`ref-${section.id}`} className="scroll-mt-24">
            <div className="mb-3 flex items-center gap-3">
              <CardIconBox variant="primary" size="md">
                <Icon className="!text-2xl" />
              </CardIconBox>
              <div>
                <h3 className="font-display text-body-lg font-semibold text-on-surface">
                  {t(`docs.ref.groups.${section.key}.title`)}
                </h3>
                <p className="text-metadata text-on-surface-variant">
                  <InlineMarkdown text={t(`docs.ref.groups.${section.key}.hint`)} />
                </p>
              </div>
            </div>
            <div className="overflow-hidden rounded-2xl border border-outline-variant/20 bg-surface-container-lowest shadow-ambient divide-y divide-outline-variant/15">
              {items.map((entry, index) => (
                <EndpointCard
                  key={entry.id}
                  entry={entry}
                  lang={lang}
                  onLangChange={onLangChange}
                  open={Boolean(expanded[entry.id])}
                  onToggle={() => toggle(entry.id)}
                  isFirst={index === 0}
                  isLast={index === items.length - 1}
                />
              ))}
            </div>
          </section>
        );
      })}
    </div>
  );
}
