'use client';

import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { bytes } from '@/lib/format';
import { parseXlsx, xlsxColumnLabel, type ParsedWorkbook } from '@/lib/office-parsers';
import { Alert } from '@/components/Alert';
import { Spinner } from '@/components/Spinner';
import { Tabs } from '@/components/Tabs';
import { DataTable, type DataTableCell } from '@/components/DataTable';
import { OFFICE_PREVIEW_MAX_BYTES, PreviewFallback } from '@/components/office/DocxViewer';

type Props = {
  fileId: string;
  fileName: string;
  fileSize: number;
  src: string;
  downloadUrl: string;
};

/** XLSX preview: sheets parsed client-side (workbook + shared strings + worksheet XML). */
export function XlsxViewer({ fileId, fileName, fileSize, src, downloadUrl }: Props) {
  const { t } = useTranslation();
  const tooLarge = fileSize > OFFICE_PREVIEW_MAX_BYTES;
  const [workbook, setWorkbook] = useState<ParsedWorkbook | null>(null);
  const [active, setActive] = useState(0);
  const [loading, setLoading] = useState(!tooLarge);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (tooLarge) return;
    let cancelled = false;
    setWorkbook(null);
    setFailed(false);
    setLoading(true);
    fetch(src)
      .then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.arrayBuffer();
      })
      .then(parseXlsx)
      .then((parsed) => {
        if (cancelled) return;
        setWorkbook(parsed);
        setActive(0);
      })
      .catch(() => { if (!cancelled) setFailed(true); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [fileId, src, tooLarge]);

  const sheet = workbook?.sheets[active] ?? null;

  const truncation = useMemo(
    () => ({
      rows: sheet && sheet.hiddenRows > 0 ? sheet.hiddenRows : null,
      columns: sheet && sheet.hiddenColumns > 0 ? sheet.hiddenColumns : null,
    }),
    [sheet],
  );

  const table = useMemo(() => {
    if (!sheet) return null;
    const columns = Array.from({ length: sheet.columns }, (_, i) => ({
      key: `c${i}`,
      label: xlsxColumnLabel(i),
    }));
    const rows: DataTableCell[][] = sheet.rows.map((row) =>
      row.map((cell) => ({ value: cell?.text ?? '', numeric: cell?.numeric ?? false })),
    );
    return { columns, rows };
  }, [sheet]);

  if (tooLarge) {
    return (
      <PreviewFallback
        icon="table_chart"
        title={t('preview.tooLargeOffice')}
        detail={`${fileName} • ${bytes(fileSize)}`}
        downloadUrl={downloadUrl}
        downloadLabel={t('preview.downloadFile')}
      />
    );
  }

  let body: ReactNode;
  if (loading) {
    body = (
      <div className="flex-1 flex flex-col items-center justify-center gap-3 text-outline" role="status">
        <Spinner size="md" label={t('preview.loadingXlsx')} />
        <p className="text-sm">{t('preview.loadingXlsx')}</p>
      </div>
    );
  } else if (failed) {
    body = (
      <div className="flex-1 flex flex-col items-center justify-center gap-3 p-6">
        <Alert tone="danger">{t('preview.errorXlsx')}</Alert>
      </div>
    );
  } else if (!workbook || workbook.sheets.length === 0) {
    body = (
      <div className="flex-1 flex items-center justify-center text-outline text-sm p-6" role="status">
        {t('preview.emptyXlsx')}
      </div>
    );
  } else if (sheet && table) {
    body = (
      <div className="flex-1 flex flex-col min-h-0 gap-2">
        {sheet.columns === 0 || sheet.rows.length === 0 ? (
          <div className="flex-1 flex items-center justify-center text-outline text-sm p-6" role="status">
            {t('preview.emptySheet')}
          </div>
        ) : (
          <DataTable
            data-testid="xlsx-table"
            className="bg-transparent rounded-none border-0"
            label={sheet.name}
            columns={table.columns}
            rows={table.rows}
            rowHeader={(i) => sheet.firstRowNumber + i}
          />
        )}
        {(truncation.rows !== null || truncation.columns !== null) && (
          <p className="shrink-0 text-xs text-outline tabular-nums px-1">
            {truncation.rows !== null && t('preview.moreRows', { count: truncation.rows })}
            {truncation.rows !== null && truncation.columns !== null && <span aria-hidden> · </span>}
            {truncation.columns !== null && t('preview.moreColumns', { count: truncation.columns })}
          </p>
        )}
      </div>
    );
  }

  return (
    <div
      data-testid="xlsx-viewer"
      className="flex-1 flex flex-col overflow-hidden min-h-0 h-full w-full max-w-6xl mx-auto px-2 sm:px-4 py-3 sm:py-4"
      onClick={(e) => e.stopPropagation()}
    >
      <div className="min-w-0 overflow-x-auto mb-2 shrink-0">
        {workbook && workbook.sheets.length > 0 && (
          <Tabs
            tabs={workbook.sheets.map((s, i) => ({ value: String(i), label: s.name }))}
            value={String(active)}
            onChange={(v) => setActive(Number(v))}
          />
        )}
      </div>
      {body}
    </div>
  );
}
