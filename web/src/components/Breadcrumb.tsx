'use client';

import Link from 'next/link';
import { ChevronRight } from '@mui/icons-material';
import clsx from 'clsx';

export type Crumb = {
  id: string | null; // null = root
  label: string;
};

type Props = {
  items: Crumb[];
  /** Override root path. Default: /files */
  rootHref?: string;
  /** Custom href template for a crumb. Receives id, returns href. */
  hrefFor?: (id: string) => string;
  /** Visual size. */
  size?: 'sm' | 'md' | 'lg';
  className?: string;
};

const sizeClass: Record<NonNullable<Props['size']>, string> = {
  sm: 'text-sm',
  md: 'text-base',
  lg: 'text-3xl',
};

export function Breadcrumb({
  items,
  rootHref = '/files',
  hrefFor,
  size = 'lg',
  className,
}: Props) {
  const isHeading = size === 'lg';
  const lastIndex = items.length - 1;
  const chevronSize = isHeading ? '!text-2xl' : '!text-base';

  return (
    <nav
      aria-label="Breadcrumb"
      className={clsx('flex items-center gap-2 flex-wrap', className)}
    >
      {items.map((c, i) => {
        const isLast = i === lastIndex;
        const href =
          c.id === null
            ? rootHref
            : (hrefFor ? hrefFor(c.id) : `/files/${c.id}`);

        return (
          <span key={`${c.label}-${i}`} className="flex items-center gap-2">
            {i > 0 && (
              <ChevronRight className={clsx('text-outline shrink-0', chevronSize)} aria-hidden />
            )}
            {isLast ? (
              <span
                aria-current="page"
                className={clsx(
                  'font-semibold text-on-surface truncate',
                  isHeading ? 'font-display' : '',
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
                  'text-outline hover:text-on-surface transition-colors truncate',
                  sizeClass[size],
                )}
              >
                {c.label}
              </Link>
            )}
          </span>
        );
      })}
    </nav>
  );
}
