'use client';

import type { ReactNode } from 'react';
import clsx from 'clsx';
import { PublicHeader } from '@/components/PublicHeader';
import { PublicFooter } from '@/components/PublicFooter';
import type { PublicNavItem } from '@/lib/site';

type Props = {
  children: ReactNode;
  /** Override the header navigation for this surface. */
  navItems?: PublicNavItem[];
  /** Section id to highlight in the header (landing only). */
  activeSection?: string;
  /** Extra classes for the content wrapper. */
  mainClassName?: string;
  /** Hide the footer "back to top" control on short pages. */
  showBackToTop?: boolean;
};

/**
 * Wrapper for every public (unauthenticated) surface: the landing page, the
 * legal portal, and the API documentation. Header and footer come from the
 * shared components, so one edit updates every public page at once.
 */
export function PublicShell({
  children,
  navItems,
  activeSection,
  mainClassName,
  showBackToTop = true,
}: Props) {
  return (
    <div className="min-h-screen bg-background font-body text-on-surface">
      <PublicHeader navItems={navItems} activeSection={activeSection} />
      <main className={clsx('min-w-0', mainClassName)}>{children}</main>
      <PublicFooter showBackToTop={showBackToTop} />
    </div>
  );
}
