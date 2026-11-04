'use client';

import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Download } from '@mui/icons-material';
import { bytes } from '@/lib/format';
import { parseDocx, type DocxBlock, type DocxRun } from '@/lib/office-parsers';
import { Alert } from '@/components/Alert';
import { LinkButton } from '@/components/Button';
import { Spinner } from '@/components/Spinner';

export const OFFICE_PREVIEW_MAX_BYTES = 20 * 1024 * 1024;

type Props = {
  fileId: string;
  fileName: string;
  fileSize: number;
  /** Stream URL (inline, with token) used to fetch the raw archive. */
  src: string;
  downloadUrl: string;
};

/** Message card used for "too large" / "parse failed" states. */
export function PreviewFallback({
  icon,
  title,
  detail,
  downloadUrl,
  downloadLabel,
}: {
  icon: string;
  title: string;
  detail?: string;
  downloadUrl: string;
  downloadLabel: string;
}) {
  return (
    <div
      className="flex-1 flex flex-col items-center justify-center gap-3 p-6 text-center"
      onClick={(e) => e.stopPropagation()}
    >
      <span className="material-symbols-outlined !text-5xl text-outline" aria-hidden>{icon}</span>
      <p className="text-on-surface font-medium max-w-md">{title}</p>
      {detail && <p className="text-outline text-sm break-all">{detail}</p>}
      <LinkButton
        variant="primary"
        size="pill"
        href={downloadUrl}
        download
        leftIcon={<Download className="!text-base" />}
        className="mt-2"
      >
        {downloadLabel}
      </LinkButton>
    </div>
  );
}

function Runs({ runs }: { runs: DocxRun[] }) {
  return (
    <>
      {runs.map((run, i) => {
        const Tag = run.bold ? 'strong' : run.italic ? 'em' : 'span';
        return <Tag key={i}>{run.text}</Tag>;
      })}
    </>
  );
}

const headingClass: Record<1 | 2 | 3, string> = {
  1: 'text-xl sm:text-2xl font-display font-semibold text-on-surface mt-5 mb-2 first:mt-0',
  2: 'text-lg sm:text-xl font-display font-semibold text-on-surface mt-4 mb-1.5 first:mt-0',
  3: 'text-base sm:text-lg font-semibold text-on-surface mt-3 mb-1 first:mt-0',
};

/** Nesting indent per numbering level (static utility classes, no inline style). */
const LIST_INDENT = ['pl-0', 'pl-4', 'pl-8', 'pl-12'] as const;

function DocxBlockView({ block }: { block: DocxBlock }) {
  if (block.kind === 'heading') {
    const Tag = `h${block.level}` as 'h1' | 'h2' | 'h3';
    return <Tag className={headingClass[block.level]}><Runs runs={block.runs} /></Tag>;
  }
  if (block.kind === 'listItem') {
    return (
      <div
        className={
          'flex gap-2 text-sm text-on-surface leading-relaxed mb-1 ' +
          LIST_INDENT[block.level]
        }
      >
        <span className="shrink-0 text-outline select-none" aria-hidden>
          {block.ordered ? `${block.level + 1}.` : '•'}
        </span>
        <span className="min-w-0 [overflow-wrap:anywhere]"><Runs runs={block.runs} /></span>
      </div>
    );
  }
  if (block.kind === 'table') {
    const columnCount = block.rows.reduce((m, r) => Math.max(m, r.length), 0);
    return (
      <div className="my-3 rounded-lg border border-outline-variant/40 overflow-x-auto">
        <div role="table" className="min-w-full text-sm text-on-surface">
          {block.rows.map((row, r) => (
            <div key={r} role="row" className="flex border-b border-outline-variant/25 last:border-b-0">
              {Array.from({ length: columnCount }, (_, c) => (
                <div
                  key={c}
                  role="cell"
                  className={
                    'flex-1 min-w-0 px-3 py-1.5 border-r border-outline-variant/25 last:border-r-0 [overflow-wrap:anywhere] ' +
                    (r === 0 ? 'font-semibold bg-surface-container/40' : '')
                  }
                >
                  <Runs runs={row[c] ?? []} />
                </div>
              ))}
            </div>
          ))}
        </div>
      </div>
    );
  }
  return (
    <p className="text-sm text-on-surface leading-relaxed mb-2 [overflow-wrap:anywhere]">
      <Runs runs={block.runs} />
    </p>
  );
}

/** DOCX preview parsed client-side from `word/document.xml` via JSZip. */
export function DocxViewer({ fileId, fileName, fileSize, src, downloadUrl }: Props) {
  const { t } = useTranslation();
  const tooLarge = fileSize > OFFICE_PREVIEW_MAX_BYTES;
  const [blocks, setBlocks] = useState<DocxBlock[] | null>(null);
  const [loading, setLoading] = useState(!tooLarge);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (tooLarge) return;
    let active = true;
    setBlocks(null);
    setFailed(false);
    setLoading(true);
    fetch(src)
      .then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.arrayBuffer();
      })
      .then(parseDocx)
      .then((parsed) => { if (active) setBlocks(parsed); })
      .catch(() => { if (active) setFailed(true); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [fileId, src, tooLarge]);

  if (tooLarge) {
    return (
      <PreviewFallback
        icon="draft"
        title={t('preview.tooLargeOffice')}
        detail={`${fileName} • ${bytes(fileSize)}`}
        downloadUrl={downloadUrl}
        downloadLabel={t('preview.downloadFile')}
      />
    );
  }

  const toolbar = (
    <div className="flex items-center justify-between gap-2 mb-2 shrink-0">
      <span className="text-xs text-outline truncate">{t('preview.docxLabel')}</span>
      <LinkButton
        variant="secondary"
        size="sm"
        href={downloadUrl}
        download
        leftIcon={<Download className="!text-base" />}
        className="!px-3 !py-1.5 !h-auto text-xs"
      >
        {t('preview.downloadFile')}
      </LinkButton>
    </div>
  );

  let body: ReactNode;
  if (loading) {
    body = (
      <div className="flex-1 flex flex-col items-center justify-center gap-3 text-outline" role="status">
        <Spinner size="md" label={t('preview.loadingDocx')} />
        <p className="text-sm">{t('preview.loadingDocx')}</p>
      </div>
    );
  } else if (failed) {
    body = (
      <div className="flex-1 flex flex-col items-center justify-center gap-3 p-6">
        <Alert tone="danger">{t('preview.errorDocx')}</Alert>
      </div>
    );
  } else if (!blocks || blocks.length === 0) {
    body = (
      <div className="flex-1 flex items-center justify-center text-outline text-sm p-6" role="status">
        {t('preview.emptyDocx')}
      </div>
    );
  } else {
    body = (
      <article data-testid="docx-content" className="flex-1 min-h-0 overflow-y-auto overscroll-contain pr-1">
        {blocks.map((block, i) => <DocxBlockView key={i} block={block} />)}
      </article>
    );
  }

  return (
    <div
      data-testid="docx-viewer"
      className="flex-1 flex flex-col overflow-hidden min-h-0 h-full w-full max-w-3xl mx-auto px-4 sm:px-6 py-3 sm:py-4"
      onClick={(e) => e.stopPropagation()}
    >
      {toolbar}
      {body}
    </div>
  );
}
