'use client';

import { Fragment } from 'react';
import clsx from 'clsx';

/** Matches `code`, **bold**, and *italic* runs — nothing block-level. */
const TOKEN = /(`[^`\n]+`|\*\*[^*\n]+\*\*|\*[^*\n]+\*)/g;

const CODE_CLASS = 'rounded bg-surface-container-high px-1.5 py-0.5 font-mono text-primary text-xs';

/**
 * Tiny inline markdown renderer for strings that must keep their literal line
 * breaks (legal articles, docs copy): backticks become <code>, asterisk runs
 * become <strong>/<em>. Content is composed as React nodes, never injected as
 * HTML, so untranslated copy can't smuggle markup into the page.
 */
export function InlineMarkdown({ text, className }: { text: string; className?: string }) {
  const parts = typeof text === 'string' ? text.split(TOKEN) : [];

  return (
    <span className={clsx(className)}>
      {parts.map((part, index) => {
        if (!part) return null;
        if (part.startsWith('`') && part.endsWith('`') && part.length > 2) {
          return (
            <code key={index} className={CODE_CLASS}>
              {part.slice(1, -1)}
            </code>
          );
        }
        if (part.startsWith('**') && part.endsWith('**') && part.length > 4) {
          return <strong key={index}>{part.slice(2, -2)}</strong>;
        }
        if (part.startsWith('*') && part.endsWith('*') && part.length > 2) {
          return <em key={index}>{part.slice(1, -1)}</em>;
        }
        return <Fragment key={index}>{part}</Fragment>;
      })}
    </span>
  );
}
