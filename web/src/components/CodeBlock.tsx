'use client';

import { useMemo, useState } from 'react';
import { CheckCircle, CopyAll } from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';

type TokenClass = 'comment' | 'string' | 'flag' | 'keyword' | 'number' | 'plain';

const TOKEN_CLASS: Record<TokenClass, string> = {
  comment: 'text-outline italic',
  string: 'text-secondary',
  flag: 'text-primary font-semibold',
  keyword: 'text-primary font-semibold',
  number: 'text-on-surface-variant tabular-nums',
  plain: 'text-on-surface',
};

const TOKEN_PATTERN =
  /(#[^\n]*|\/\/[^\n]*)|("(?:[^"\\\n]|\\.)*"|'(?:[^'\\\n]|\\.)*')|(-{1,2}[A-Za-z-]+)|\b(curl|export|Bearer|GET|POST|PUT|PATCH|DELETE|HTTP\/1\.1)\b|(\b\d+(?:\.\d+)?\b)/g;

function tokenize(code: string): Array<{ text: string; kind: TokenClass }> {
  const tokens: Array<{ text: string; kind: TokenClass }> = [];
  let last = 0;
  let match: RegExpExecArray | null;
  TOKEN_PATTERN.lastIndex = 0;
  while ((match = TOKEN_PATTERN.exec(code)) !== null) {
    if (match.index > last) tokens.push({ text: code.slice(last, match.index), kind: 'plain' });
    const kind: TokenClass = match[1]
      ? 'comment'
      : match[2]
        ? 'string'
        : match[3]
          ? 'flag'
          : match[4]
            ? 'keyword'
            : 'number';
    tokens.push({ text: match[0], kind });
    last = match.index + match[0].length;
  }
  if (last < code.length) tokens.push({ text: code.slice(last), kind: 'plain' });
  return tokens;
}

export function HighlightedCode({ code, className }: { code: string; className?: string }) {
  const tokens = useMemo(() => tokenize(code), [code]);
  return (
    <pre className={clsx('max-h-[420px] overflow-auto p-4 text-metadata leading-relaxed', className)}>
      <code className="font-mono whitespace-pre">
        {tokens.map((token, index) => (
          <span key={`${index}-${token.text.slice(0, 12)}`} className={TOKEN_CLASS[token.kind]}>
            {token.text}
          </span>
        ))}
      </code>
    </pre>
  );
}

type Props = {
  code: string;
  /** Filename shown in the terminal-style header, e.g. `01-upload.sh`. */
  file?: string;
  labelKey?: string;
  maxHeightClass?: string;
  className?: string;
};

/**
 * Copyable terminal card shared by the landing API teaser and the /docs
 * reference, so both render identical cURL walks.
 */
export function CodeBlock({ code, file, labelKey, maxHeightClass, className }: Props) {
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
        <div className="flex items-center gap-2 border-b border-outline-variant/20 bg-surface-container-high px-4 py-3">
          <p className="min-w-0 flex-1 truncate font-mono text-metadata text-on-surface-variant">
            {file ? `${t('docs.consolePrompt')} ${file}` : `${t('docs.consolePrompt')} ./call.sh`}
          </p>
          <Button
            type="button"
            size="sm"
            variant="ghost"
            onClick={copy}
            leftIcon={copied ? <CheckCircle className="!text-lg" /> : <CopyAll className="!text-lg" />}
            className={clsx('!h-8 shrink-0 !text-on-surface-variant', copied && '!text-primary')}
          >
            {failed
              ? t('landing.api.copyFailed')
              : copied
                ? t('landing.api.copied')
                : t('landing.api.copy')}
          </Button>
        </div>
        <HighlightedCode code={code} className={maxHeightClass} />
      </Card>
    </div>
  );
}
