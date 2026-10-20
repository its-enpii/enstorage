'use client';

import Link from 'next/link';
import { ChevronRight, MoreHoriz } from '@mui/icons-material';
import clsx from 'clsx';
import { Fragment, useEffect, useRef, useState } from 'react';
import { IconButton } from '@/components/Button';
import { Tooltip } from '@/components/Tooltip';

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
   * resolution). They are the first to give up room on narrow headers.
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
};

// `lg` is the page-heading size. On phones it steps down to 18px so the
// breadcrumb + trailing actions can share a single row; from `sm` up (640px+)
// it keeps the original 30px display size.
const sizeClass: Record<NonNullable<Props['size']>, string> = {
  sm: 'text-sm',
  md: 'text-base',
  lg: 'text-lg sm:text-3xl',
};

export function Breadcrumb({
  items,
  rootHref = '/files',
  hrefFor,
  hrefForIndex,
  size = 'lg',
  className,
}: Props) {
  const isHeading = size === 'lg';
  const chevronSize = isHeading ? '!text-2xl' : '!text-base';
  const lastIndex = items.length - 1;
  // When there are intermediate crumbs (beyond root + current page), the
  // middle run is collapsed into a single "…" once the header runs out of
  // room. Root and the current page are never collapsed.
  const hasMiddle = lastIndex > 1;
  const middle = hasMiddle ? items.slice(1, lastIndex) : [];
  const navRef = useRef<HTMLElement | null>(null);
  const [expandedMiddle, setExpandedMiddle] = useState(false);
  const showMiddle = hasMiddle && expandedMiddle;
  const middleLabel = middle.map((m) => m.label).join(' / ');

  // Collapse the middle run back to "…" as soon as it would no longer fit —
  // the current page must always keep its full label on screen.
  useEffect(() => {
    if (!showMiddle) return;
    const nav = navRef.current;
    if (!nav || typeof ResizeObserver === 'undefined') return;
    const collapseIfOverflowing = () => {
      if (nav.scrollWidth > nav.clientWidth + 1) setExpandedMiddle(false);
    };
    const ro = new ResizeObserver(collapseIfOverflowing);
    ro.observe(nav);
    return () => ro.disconnect();
  }, [showMiddle]);

  const hrefOf = (c: Crumb, i: number) =>
    c.id === null
      ? rootHref
      : (c.href
        ?? (hrefForIndex ? hrefForIndex(c, i) : undefined)
        ?? (hrefFor ? hrefFor(c.id) : `/files/${c.id}`));

  return (
    <nav
      ref={navRef}
      aria-label="Breadcrumb"
      className={clsx('min-w-0', className)}
    >
      {items.map((c, i) => {
        const isLast = i === lastIndex;
        const isMiddle = hasMiddle && i > 0 && i < lastIndex;
        const isCollapsedStart = isMiddle && !showMiddle && i === 1;

        if (isMiddle && !isCollapsedStart) return null;

        const href = hrefOf(c, i);

        // Earlier crumbs are secondary: they truncate (root) or collapse (trail),
        // the current page keeps its full label and may wrap to a second line.
        return (
          <Fragment key={`${c.label}-${i}`}>
            {i > 0 && !isCollapsedStart && (
              <ChevronRight className={clsx('text-outline shrink-0', chevronSize)} aria-hidden />
            )}
            {isCollapsedStart ? (
              <>
                <ChevronRight className={clsx('text-outline shrink-0', chevronSize)} aria-hidden />
                <Tooltip label={middleLabel}>
                  <IconButton
                    type="button"
                    size="sm"
                    shape="circle"
                    aria-label={middleLabel}
                    aria-expanded={showMiddle}
                    onClick={() => setExpandedMiddle(true)}
                    className="shrink-0 font-bold"
                  >
                    <MoreHoriz className={chevronSize} />
                  </IconButton>
                </Tooltip>
                <ChevronRight className={clsx('text-outline shrink-0', chevronSize)} aria-hidden />
              </>
            ) : null}
            {isLast ? (
              <span
                aria-current="page"
                className={clsx(
                  'min-w-0 break-words text-on-surface font-semibold',
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
                  'shrink truncate text-outline hover:text-on-surface transition-colors',
                  isMiddle && showMiddle ? 'shrink-[3]' : '',
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
