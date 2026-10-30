'use client';

import { useEffect, useRef } from 'react';
import { marked, type Token, type Tokens } from 'marked';

const MERMAID_KEYWORDS = [
  'flowchart',
  'graph',
  'sequenceDiagram',
  'classDiagram',
  'stateDiagram',
  'erDiagram',
  'gantt',
  'pie',
  'gitGraph',
  'journey',
  'mindmap',
  'timeline',
  'quadrantChart',
  'C4Context',
  'sankey',
  'block-beta',
];

const MERMAID_THEME = {
  startOnLoad: false,
  theme: 'dark' as const,
  securityLevel: 'loose' as const,
  fontFamily: 'inherit',
  themeVariables: {
    darkMode: true,
    background: 'transparent',
    primaryColor: '#c6c0ff',
    primaryTextColor: '#111319',
    primaryBorderColor: '#585666',
    lineColor: '#c6c0ff',
    secondaryColor: '#3d348b',
    tertiaryColor: '#1d1f27',
  },
};

export function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function isDiagramLanguage(lang: string | undefined): boolean {
  return (lang ?? '').trim().toLowerCase() === 'mermaid';
}

function startsWithDiagramKeyword(code: string): boolean {
  const firstLine = code.replace(/^\s+/, '').split('\n')[0]?.trim() ?? '';
  return MERMAID_KEYWORDS.some(
    (kw) => firstLine === kw || firstLine.startsWith(`${kw} `) || firstLine.startsWith(`${kw}\t`),
  );
}

function isDiagramBlock(token: Tokens.Code): boolean {
  if (isDiagramLanguage(token.lang)) return true;
  const lang = (token.lang ?? '').trim();
  return (lang === '' || lang.toLowerCase() === 'text') && startsWithDiagramKeyword(token.text);
}

function renderDiagram(code: string): string {
  return (
    `<div class="mermaid-container my-4 overflow-x-auto rounded-2xl border border-outline-variant/30 bg-surface-container-lowest/80 p-4">` +
    `<div class="mermaid flex justify-center text-center">${escapeHtml(code)}</div>` +
    `</div>`
  );
}

/**
 * Renders markdown to HTML, transforming Mermaid diagram code blocks into
 * `.mermaid` containers that are hydrated client-side by {@link useMermaid}.
 */
export function renderMarkdownWithDiagrams(markdown: string): string {
  const renderer = new marked.Renderer();
  renderer.code = ({ text, lang }: Tokens.Code) => {
    const token = { text, lang } as Tokens.Code;
    if (isDiagramBlock(token)) return renderDiagram(text);
    return `<pre><code class="language-${escapeHtml(lang || '')}">${escapeHtml(text)}</code></pre>`;
  };
  const html = marked.parse(markdown, { gfm: true, breaks: true, renderer, async: false }) as string;
  return html;
}

/** Initialize Mermaid (once) and render every unprocessed diagram inside `root`. */
export async function renderMermaidIn(root: HTMLElement): Promise<void> {
  const nodes = Array.from(
    root.querySelectorAll<HTMLElement>('.mermaid:not([data-processed="true"])'),
  );
  if (nodes.length === 0) return;
  try {
    const mermaid = (await import('mermaid')).default;
    mermaid.initialize(MERMAID_THEME);
    await mermaid.run({ nodes });
  } catch (err) {
    // Invalid user diagram syntax: keep the original code visible, never crash.
    console.warn('Mermaid render failed:', err);
  }
}

/**
 * Hydrates `.mermaid` blocks inside the returned ref whenever `deps`
 * change (e.g. markdown content or the active tab switches to preview).
 */
export function useMermaid<T extends HTMLElement>(deps: unknown[]) {
  const ref = useRef<T>(null);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    void renderMermaidIn(el);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps);
  return ref;
}
