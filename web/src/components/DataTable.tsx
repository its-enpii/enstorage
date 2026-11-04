'use client';

import type { ReactNode } from 'react';
import clsx from 'clsx';

/** Single cell of a `DataTable`. Numbers are right-aligned with tabular figures. */
export type DataTableCell = {
  value: string;
  /** Render with tabular figures and right alignment (numeric cells). */
  numeric?: boolean;
};

export type DataTableColumn = {
  key: string;
  label: ReactNode;
};

type Props = {
  columns: DataTableColumn[];
  rows: DataTableCell[][];
  /** Accessible name of the table. */
  label: string;
  /** Optional row label (e.g. spreadsheet row number), rendered as a leading sticky column. */
  rowHeader?: (rowIndex: number) => ReactNode;
  /** Extra vertical scroll container height class (defaults to fill the parent). */
  className?: string;
  'data-testid'?: string;
};

/**
 * Catalog data table: semantic `<table>` with token-based borders, sticky
 * header (and sticky row-header column when supplied) and horizontal/vertical
 * scrolling. Reusable by the spreadsheet preview and the archive listing.
 */
export function DataTable({ columns, rows, label, rowHeader, className, 'data-testid': dataTestId }: Props) {
  return (
    <div
      className={clsx(
        'flex-1 min-h-0 w-full overflow-auto overscroll-contain rounded-lg border border-outline-variant/30 bg-surface-container-lowest/40',
        className,
      )}
    >
      <table
        data-testid={dataTestId}
        aria-label={label}
        className="w-full border-collapse text-sm text-on-surface"
      >
        <thead>
          <tr>
            {rowHeader && (
              <th
                scope="col"
                className="sticky top-0 left-0 z-10 w-12 min-w-12 bg-surface-container-high border-b border-r border-outline-variant/40 px-2 py-1.5 text-right text-xs font-semibold text-outline"
              />
            )}
            {columns.map((col) => (
              <th
                key={col.key}
                scope="col"
                className="sticky top-0 z-10 min-w-24 whitespace-nowrap bg-surface-container-high border-b border-r border-outline-variant/40 px-3 py-1.5 text-left text-xs font-semibold text-on-surface-variant last:border-r-0"
              >
                {col.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, rowIndex) => (
            <tr key={rowIndex} className="even:bg-surface-container/30 hover:bg-surface-container/60">
              {rowHeader && (
                <th
                  scope="row"
                  className="sticky left-0 z-10 bg-surface-container-lowest border-b border-r border-outline-variant/25 px-2 py-1.5 text-right text-xs font-normal text-outline tabular-nums"
                >
                  {rowHeader(rowIndex)}
                </th>
              )}
              {columns.map((col, colIndex) => {
                const cell = row[colIndex];
                return (
                  <td
                    key={col.key}
                    className={clsx(
                      'border-b border-r border-outline-variant/25 px-3 py-1.5 align-top whitespace-pre-wrap [overflow-wrap:anywhere] last:border-r-0',
                      cell?.numeric && 'text-right tabular-nums',
                    )}
                  >
                    {cell?.value ?? ''}
                  </td>
                );
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
