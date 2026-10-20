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
}: Props) {
  const isHeading = size === 'lg';
  const navRef = useRef<HTMLElement | null>(null);
  const [expandedMiddle, setExpandedMiddle] = useState(false);
  const lastIndex = items.length - 1;
  // When there are intermediate crumbs (beyond root + current page), the
  // middle run is collapsed into a single "…" chip so the trail stays on one
  // line: `root > … > active`. Root and the current page are never collapsed.
  const hasMiddle = lastIndex > 1;
  const middle = hasMiddle ? items.slice(1, lastIndex) : [];
  const showMiddle = hasMiddle && expandedMiddle;
  const middleLabel = middle.map((m) => m.label).join(' / ');
  // Chevrons scale with the heading so the whole trail stays 1 row tall on
  // phones (30px chevrons alone would exceed the 40px header budget).
  const chevronSize = isHeading ? '!text-xl md:!text-2xl' : '!text-base';

  // Collapse an expanded middle run back to "…" once it no longer fits —
  // the current page must always keep its full label on screen.
  useEffect(() => {
    if (!showMiddle) return;
    const nav = navRef.current;
    if (!nav || typeof ResizeObserver === 'undefined') return;
    const collapseIfOverflowing = () => {
      if (nav.scrollWidth > nav.clientWidth + 1 || nav.scrollHeight > nav.clientHeight + 1) {
        setExpandedMiddle(false);
      }
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
      className={clsx('min-w-0 flex-nowrap', className)}
    >
      {items.map((c, i) => {
        const isLast = i === lastIndex;
        const isMiddle = hasMiddle && i > 0 && i < lastIndex;
        const isCollapsedStart = isMiddle && !showMiddle && i === 1;

        // Middle crumbs are hidden only while collapsed (and not the "…" chip).
        // When expanded (showMiddle), they fall through to the normal Link render.
        if (isMiddle && !showMiddle && !isCollapsedStart) return null;

        const href = hrefOf(c, i);

        // The first collapsed middle crumb renders the "…" chip only and
        // stops here — it must not also fall through to the Link/span below
        // (that rendered the same label twice and overflowed the nav).
        if (isCollapsedStart) {
          return (
            <Fragment key={`${c.label}-${i}`}>
              <ChevronRight className={clsx('text-outline shrink-0', chevronSize)} aria-hidden />
              <Tooltip label={middleLabel}>
                <IconButton
                  type="button"
                  size="sm"
                  shape="circle"
                  aria-label={middleLabel}
                  aria-expanded={showMiddle}
                  onClick={() => setExpandedMiddle(true)}
                  className="shrink-0 font-bold !h-5 !w-5 md:!h-7 md:!w-7"
                >
                  <MoreHoriz className={isHeading ? '!text-lg md:!text-2xl' : '!text-base'} />
                </IconButton>
              </Tooltip>
              <ChevronRight className={clsx('text-outline shrink-0', chevronSize)} aria-hidden />
            </Fragment>
          );
        }

        // Root is full but may truncate as a last resort; the current page
        // always keeps its full label.
        return (
          <Fragment key={`${c.label}-${i}`}>
            {i > 0 && (
              <ChevronRight className={clsx('text-outline shrink-0', chevronSize)} aria-hidden />
            )}
            {isLast ? (
              <span
                aria-current="page"
                className={clsx(
                  'min-w-0 shrink break-words text-on-surface font-semibold',
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
