'use client';

import Link from 'next/link';
import { ChevronRight } from '@mui/icons-material';
import clsx from 'clsx';
import { Fragment, type MouseEventHandler } from 'react';

export type Crumb = {
  id: string | null; // null = root
  label: string;
  /**
   * Optional explicit href. When set, it wins over `hrefFor`/default
   * `/files/{id}` derivation. Used for slug/path-based routing where the
   * crumb resolves to a materialized path (e.g. `/files/sidbm/logo`)
   * rather than a UUID.
   */
  href?: string;
  /**
   * Ancestors of the current page (e.g. the trail returned by folder
   * resolution). Kept for backward compatibility; ancestors are no longer
   * truncated unless extremely long.
   */
  trail?: boolean;
};

type Props = {
  items: Crumb[];
  /** Override root path. Default: /files */
  rootHref?: string;
  /** Custom href template for a crumb. Receives id, returns href. */
  hrefFor?: (id: string) => string;
  /**
   * Custom href resolver receiving the crumb + its index. Takes
   * precedence over `hrefFor` and the default `/files/{id}` derivation,
   * but yields to an explicit `crumb.href`. Enables slug/path routing.
   */
  hrefForIndex?: (crumb: Crumb, index: number) => string;
  /** Visual size. */
  size?: 'sm' | 'md' | 'lg';
  className?: string;
  /**
   * Capture-phase click pada nav. Dipakai halaman untuk menyimpan posisi
   * scroll SEBELUM <Link> memicu navigasi.
   */
  onClickCapture?: MouseEventHandler<HTMLElement>;
};

// `lg` is the page-heading size. On phones it steps down to 17px so the
// breadcrumb + trailing actions can share a single row (root > … > active);
// from `md` up it steps to 22px and only reaches the 30px display size once
// the header is wide enough for the trail to stay on one line.
const sizeClass: Record<NonNullable<Props['size']>, string> = {
  sm: 'text-sm',
  md: 'text-base',
  lg: 'text-[17px] leading-6 md:text-[22px] md:leading-7 xl:text-3xl xl:leading-9',
};

export function Breadcrumb({
  items,
  rootHref = '/files',
  hrefFor,
  hrefForIndex,
  size = 'lg',
  className,
  onClickCapture,
}: Props) {
  const isHeading = size === 'lg';
  const lastIndex = items.length - 1;
  // Chevrons scale with the heading so the whole trail stays 1 row tall on
  // phones (30px chevrons alone would exceed the 40px header budget).
  const chevronSize = isHeading ? '!text-xl md:!text-2xl' : '!text-base';

  const hrefOf = (c: Crumb, i: number) =>
    c.id === null
      ? rootHref
      : (c.href
        ?? (hrefForIndex ? hrefForIndex(c, i) : undefined)
        ?? (hrefFor ? hrefFor(c.id) : `/files/${c.id}`));

  // Seluruh level jalur SELALU ditampilkan (tanpa chip "…"): satu baris,
  // tidak wrap, scroll horizontal halus bila melebihi lebar header. Halaman
  // aktif tidak pernah terpotong; ancestor boleh truncate hanya jika sangat
  // panjang (max-w + truncate) sehingga tetap terlihat.
  return (
    <nav
      aria-label="Breadcrumb"
      onClickCapture={onClickCapture}
      className={clsx(
        'min-w-0 max-w-full flex items-center flex-nowrap overflow-x-auto overflow-y-hidden whitespace-nowrap overscroll-x-contain',
        '[-ms-overflow-style:none] [scrollbar-width:thin]',
        className,
      )}
    >
      {items.map((c, i) => {
        const isLast = i === lastIndex;
        const href = hrefOf(c, i);

        return (
          <Fragment key={`${c.label}-${i}`}>
            {i > 0 && (
              <ChevronRight className={clsx('text-outline shrink-0', chevronSize)} aria-hidden />
            )}
            {isLast ? (
              <span
                aria-current="page"
                className={clsx(
                  'shrink-0 max-w-[70vw] truncate text-on-surface font-semibold sm:max-w-none sm:overflow-visible',
                  isHeading ? 'sm:font-display leading-tight' : '',
                  sizeClass[size],
                )}
              >
                {c.label}
              </span>
            ) : (
              <Link
                href={href}
                prefetch
                className={clsx(
                  'shrink-0 max-w-[14rem] truncate text-outline hover:text-on-surface transition-colors',
                  sizeClass[size],
                )}
              >
                {c.label}
              </Link>
            )}
          </Fragment>
        );
      })}
    </nav>
  );
}
