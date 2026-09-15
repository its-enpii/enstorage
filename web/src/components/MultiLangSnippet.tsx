'use client';

import { useMemo } from 'react';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/Button';
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
          className="flex shrink-0 flex-wrap items-center gap-1 rounded-full bg-surface-container-lowest p-1"
        >
          {SNIPPET_LANGS.map((option) => {
            const selected = option === active;
            return (
              <Button
                key={option}
                type="button"
                role="tab"
                aria-selected={selected}
                size="sm"
                variant={selected ? 'primary' : 'ghost'}
                onClick={() => onLangChange(option)}
                className={clsx(
                  '!h-7 rounded-full px-2.5 !text-metadata',
                  !selected && '!text-on-surface-variant hover:!text-on-surface',
                )}
              >
                {t(`docs.lang.${option}`)}
              </Button>
            );
          })}
        </div>
      }
    />
  );
}
