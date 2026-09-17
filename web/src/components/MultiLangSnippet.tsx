'use client';

import { useMemo } from 'react';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { CodeBlock } from '@/components/CodeBlock';
import { buildSnippets, type SnippetCall, type SnippetSet } from '@/lib/apiSnippets';
import type { CodeLang } from '@/lib/highlight';

export type SnippetLang = keyof SnippetSet;

export const SNIPPET_LANGS: SnippetLang[] = ['curl', 'javascript', 'php', 'phpGuzzle'];

const LANG_META: Record<SnippetLang, { file: string; lang: CodeLang }> = {
  curl: { file: 'request.sh', lang: 'bash' },
  javascript: { file: 'request.mjs', lang: 'javascript' },
  php: { file: 'request.php', lang: 'php' },
  phpGuzzle: { file: 'guzzle.php', lang: 'php' },
};

type Props = {
  call: SnippetCall;
  /** Shared preference so every card on the page switches together. */
  lang: SnippetLang;
  onLangChange: (lang: SnippetLang) => void;
  /** Short label of the endpoint being documented, for screen readers. */
  label: string;
  maxHeightClass?: string;
  className?: string;
};

/**
 * Dark code box with cURL / JavaScript / PHP tabs and a copy button. The four
 * samples are generated from one `SnippetCall`, so they always describe the
 * same request.
 */
export function MultiLangSnippet({
  call,
  lang,
  onLangChange,
  label,
  maxHeightClass = 'max-h-96',
  className,
}: Props) {
  const { t } = useTranslation();
  const snippets = useMemo(() => buildSnippets(call), [call]);
  const active: SnippetLang = SNIPPET_LANGS.includes(lang) ? lang : 'curl';
  const meta = LANG_META[active];

  return (
    <CodeBlock
      code={snippets[active]}
      lang={meta.lang}
      file={meta.file}
      labelKey="docs.snippetAria"
      maxHeightClass={maxHeightClass}
      className={className}
      toolbar={
        <div
          role="tablist"
          aria-label={label}
          className="inline-flex w-fit items-center gap-0.5 rounded-lg border border-outline-variant/20 bg-surface-container-lowest p-0.5"
        >
          {SNIPPET_LANGS.map((option) => {
            const selected = option === active;
            return (
              <button
                key={option}
                type="button"
                role="tab"
                aria-selected={selected}
                onClick={() => onLangChange(option)}
                className={clsx(
                  'rounded-md px-2.5 py-1 text-xs font-medium transition-all duration-150',
                  selected
                    ? 'bg-surface-container-high text-primary font-semibold shadow-xs'
                    : 'text-on-surface-variant hover:text-on-surface hover:bg-white/[0.04]',
                )}
              >
                {t(`docs.lang.short.${option}`)}
              </button>
            );
          })}
        </div>
      }
    />
  );
}
