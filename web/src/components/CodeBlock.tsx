'use client';

import { useMemo, useState } from 'react';
import { CheckCircle, CopyAll } from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { detectLang, tokenizeCode, type CodeLang, type CodeToken, type TokenKind } from '@/lib/highlight';

const TOKEN_CLASS: Record<TokenKind, string> = {
  comment: 'text-outline italic',
  status: 'text-primary font-semibold',
  string: 'text-secondary',
  property: 'text-on-surface font-semibold',
  header: 'text-primary',
  url: 'text-on-surface-variant underline decoration-outline-variant/40 underline-offset-2',
  secret: 'text-secondary font-semibold',
  keyword: 'text-primary font-semibold',
  method: 'text-primary font-bold',
  variable: 'text-on-surface',
  flag: 'text-primary font-semibold',
  number: 'text-on-surface-variant tabular-nums',
  punct: 'text-outline',
  plain: 'text-on-surface',
};

export function HighlightedCode({
  code,
  lang,
  className,
}: {
  code: string;
  lang?: CodeLang;
  className?: string;
}) {
  const tokens = useMemo<CodeToken[]>(
    () => tokenizeCode(code, lang ?? detectLang(code)),
    [code, lang],
  );
  return (
    <pre className={clsx('max-h-[420px] overflow-auto p-4 text-metadata leading-relaxed', className)}>
      <code className="font-mono whitespace-pre">
        {tokens.map((token, index) => (
          <span key={`${index}-${token.kind}`} className={TOKEN_CLASS[token.kind]}>
            {token.text}
          </span>
        ))}
      </code>
    </pre>
  );
}

type Props = {
  code: string;
  /** Force a grammar; auto-detected when omitted. */
  lang?: CodeLang;
  /** Filename shown in the terminal-style header, e.g. `01-upload.sh`. */
  file?: string;
  labelKey?: string;
  maxHeightClass?: string;
  className?: string;
  /** Extra controls rendered in the header, e.g. the language tabs. */
  toolbar?: React.ReactNode;
};

/**
 * Copyable terminal card shared by the landing API teaser and the /docs
 * reference, so both render identical request samples.
 */
export function CodeBlock({ code, lang, file, labelKey, maxHeightClass, className, toolbar }: Props) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);
  const [failed, setFailed] = useState(false);

  async function copy() {
    try {
      await navigator.clipboard.writeText(code);
      setCopied(true);
      setFailed(false);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
      setFailed(true);
      window.setTimeout(() => setFailed(false), 2500);
    }
  }

  return (
    <div
      role="region"
      aria-label={t(labelKey ?? 'docs.consoleAria')}
      className={clsx('shadow-ambient', className)}
    >
      <Card className="overflow-hidden !bg-surface-container-lowest !p-0">
        <div className="flex flex-wrap items-center gap-2 border-b border-outline-variant/20 bg-surface-container-high px-3 py-2 sm:px-4">
          {toolbar}
          <p className="min-w-0 flex-1 truncate font-mono text-metadata text-on-surface-variant">
            {file ? `${t('docs.consolePrompt')} ${file}` : `${t('docs.consolePrompt')} ./call.sh`}
          </p>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={copy}
            leftIcon={copied ? <CheckCircle className="!text-lg" /> : <CopyAll className="!text-lg" />}
            aria-live="polite"
            className={clsx('!h-8 shrink-0 !text-on-surface-variant', copied && '!text-primary')}
          >
            {failed
              ? t('landing.api.copyFailed')
              : copied
                ? t('landing.api.copied')
                : t('landing.api.copy')}
          </Button>
        </div>
        <HighlightedCode code={code} lang={lang} className={maxHeightClass} />
      </Card>
    </div>
  );
}
