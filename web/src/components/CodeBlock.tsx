'use client';

import { useMemo, useState } from 'react';
import { CheckCircle, CopyAll } from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { detectLang, tokenizeCode, type CodeLang, type CodeToken, type TokenKind } from '@/lib/highlight';

const TOKEN_CLASS: Record<TokenKind, string> = {
  comment: 'text-syntax-comment italic',
  status: 'text-syntax-method font-semibold',
  string: 'text-syntax-string',
  property: 'text-on-surface font-semibold',
  header: 'text-syntax-header font-medium',
  url: 'text-syntax-url underline decoration-syntax-url/30 underline-offset-2',
  secret: 'text-secondary font-mono font-medium',
  keyword: 'text-syntax-keyword font-semibold',
  method: 'text-syntax-method font-bold',
  variable: 'text-syntax-variable font-medium',
  flag: 'text-syntax-flag font-medium',
  number: 'text-syntax-number tabular-nums',
  punct: 'text-syntax-punct',
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

  const lines = useMemo<CodeToken[][]>(() => {
    const result: CodeToken[][] = [];
    let current: CodeToken[] = [];
    for (const token of tokens) {
      const parts = token.text.split('\n');
      for (let i = 0; i < parts.length; i++) {
        if (parts[i] !== '') current.push({ text: parts[i], kind: token.kind });
        if (i < parts.length - 1) {
          result.push(current);
          current = [];
        }
      }
    }
    result.push(current);
    return result;
  }, [tokens]);

  const multiLine = lines.length > 1;

  return (
    <pre className={clsx('max-h-[420px] overflow-auto p-4 text-metadata leading-relaxed', className)}>
      <code className="font-mono whitespace-pre">
        {multiLine ? (
          lines.map((lineTokens, lineIndex) => (
            <span key={lineIndex} className="flex">
              <span
                aria-hidden="true"
                className="select-none w-8 pr-3 text-right text-outline/30 font-mono text-xs tabular-nums shrink-0"
              >
                {lineIndex + 1}
              </span>
              <span>
                {lineTokens.map((token, tokenIndex) => (
                  <span key={`${lineIndex}-${tokenIndex}-${token.kind}`} className={TOKEN_CLASS[token.kind]}>
                    {token.text}
                  </span>
                ))}
              </span>
            </span>
          ))
        ) : (
          tokens.map((token, index) => (
            <span key={`${index}-${token.kind}`} className={TOKEN_CLASS[token.kind]}>
              {token.text}
            </span>
          ))
        )}
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

  const filename = file ?? 'call.sh';

  return (
    <div
      role="region"
      aria-label={t(labelKey ?? 'docs.consoleAria')}
      className={clsx('min-w-0 shadow-ambient', className)}
    >
      <Card className="overflow-hidden !bg-surface-container-lowest !p-0">
        <div className="flex items-center gap-2 border-b border-outline-variant/20 bg-surface-container-high px-3 py-2 sm:px-4">
          {toolbar ? (
            <>
              {/* Left: language tabs */}
              <div className="min-w-0 flex-1">{toolbar}</div>
              {/* Right: file badge + copy button */}
              <div className="flex shrink-0 items-center gap-2">
                <span className="hidden rounded border border-outline-variant/30 bg-surface-container px-1.5 py-0.5 font-mono text-xs text-on-surface-variant sm:inline">
                  {filename}
                </span>
                <CopyButton copied={copied} failed={failed} onCopy={copy} t={t} />
              </div>
            </>
          ) : (
            <>
              {/* Left: terminal window dots + filename */}
              <div className="flex min-w-0 flex-1 items-center gap-2">
                <span className="flex shrink-0 items-center gap-1" aria-hidden="true">
                  <span className="size-2 rounded-full bg-outline-variant/40" />
                  <span className="size-2 rounded-full bg-outline-variant/40" />
                  <span className="size-2 rounded-full bg-outline-variant/40" />
                </span>
                <span className="min-w-0 truncate font-mono text-metadata text-on-surface-variant">
                  {filename}
                </span>
              </div>
              {/* Right: copy button */}
              <CopyButton copied={copied} failed={failed} onCopy={copy} t={t} />
            </>
          )}
        </div>
        <HighlightedCode code={code} lang={lang} className={maxHeightClass} />
      </Card>
    </div>
  );
}

function CopyButton({
  copied,
  failed,
  onCopy,
  t,
}: {
  copied: boolean;
  failed: boolean;
  onCopy: () => void;
  t: (key: string) => string;
}) {
  return (
    <Button
      type="button"
      size="sm"
      variant="ghost"
      onClick={onCopy}
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
  );
}
