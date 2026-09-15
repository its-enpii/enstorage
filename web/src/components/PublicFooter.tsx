'use client';

import Link from 'next/link';
import { ArrowForward, Email } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/Button';
import { PublicWordmark } from '@/components/PublicHeader';
import {
  DOCS_HREF,
  CONTACT_EMAIL,
  LEGAL_NAV,
  scrollToTop,
} from '@/lib/site';

/** Column 1 — product sections, as anchors so they resolve from any page. */
const PRODUCT_LINKS = [
  { href: '/#features', labelKey: 'landing.footer.productLinks.features' },
  { href: '/#security', labelKey: 'landing.footer.productLinks.security' },
  { href: '/#api', labelKey: 'landing.footer.productLinks.api' },
];

/** Column 3 — help. Everything stays on-site: no code hosting links. */
const HELP_LINKS = [{ href: DOCS_HREF, labelKey: 'landing.footer.helpLinks.api' }];

const COLUMN_TITLE = 'text-label-sm font-semibold uppercase tracking-[0.14em] text-outline';
const LINK_CLASS =
  'text-body-md text-on-surface-variant no-underline transition-colors hover:text-on-surface';

/**
 * The one public footer for every unauthenticated surface: Product, Legal,
 * and Help & Contact columns plus the self-hosted note. Zero GitHub links —
 * the project is presented as on-site only.
 */
export function PublicFooter({ showBackToTop = true }: { showBackToTop?: boolean }) {
  const { t } = useTranslation();

  return (
    <footer className="border-t border-outline-variant/20 bg-surface-dim">
      <div className="mx-auto w-full max-w-6xl px-4 py-14 sm:px-6">
        <div className="grid gap-10 lg:grid-cols-[1.2fr_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]">
          <div>
            <PublicWordmark />
            <p className="mt-4 max-w-xs text-body-md leading-relaxed text-on-surface-variant">
              {t('landing.footer.tagline')}
            </p>
            <p className="mt-4 text-metadata text-outline">{t('landing.footer.madeBy')}</p>
          </div>

          <div>
            <h3 className={COLUMN_TITLE}>{t('landing.footer.product')}</h3>
            <ul className="mt-4 space-y-2.5">
              {PRODUCT_LINKS.map((link) => (
                <li key={link.labelKey}>
                  <Link href={link.href} className={LINK_CLASS}>
                    {t(link.labelKey)}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          <div>
            <h3 className={COLUMN_TITLE}>{t('landing.footer.legal')}</h3>
            <ul className="mt-4 space-y-2.5">
              {LEGAL_NAV.map((link) => (
                <li key={link.href}>
                  <Link href={link.href} className={LINK_CLASS}>
                    {t(link.labelKey)}
                  </Link>
                </li>
              ))}
            </ul>
          </div>

          <div>
            <h3 className={COLUMN_TITLE}>{t('landing.footer.help')}</h3>
            <ul className="mt-4 space-y-2.5">
              {HELP_LINKS.map((link) => (
                <li key={link.labelKey}>
                  <Link href={link.href} className={LINK_CLASS}>
                    {t(link.labelKey)}
                  </Link>
                </li>
              ))}
              <li>
                <a
                  href={`mailto:${CONTACT_EMAIL}`}
                  className="inline-flex items-center gap-2 text-body-md text-on-surface-variant no-underline transition-colors hover:text-primary"
                >
                  <Email className="!text-base" />
                  {CONTACT_EMAIL}
                </a>
              </li>
            </ul>
          </div>
        </div>

        <div className="mt-12 flex flex-col gap-4 border-t border-outline-variant/20 pt-6 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex flex-col gap-1">
            <p className="text-metadata text-on-surface-variant">
              {t('landing.footer.copyright', { year: new Date().getFullYear() })}
            </p>
            <p className="text-metadata text-outline">{t('landing.footer.selfHostedNote')}</p>
          </div>
          {showBackToTop && (
            <Button
              type="button"
              size="sm"
              variant="secondary"
              onClick={scrollToTop}
              rightIcon={<ArrowForward className="!text-lg -rotate-90" />}
            >
              {t('landing.nav.backToTop')}
            </Button>
          )}
        </div>
      </div>
    </footer>
  );
}
