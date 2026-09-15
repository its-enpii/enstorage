/**
 * Single source of truth for the public site: destinations, contact address,
 * and the anchor helpers shared by the header, footer, and page sections.
 */

/** Contact address published across the landing page and legal portal. */
export const CONTACT_EMAIL = 'enpiiofficial@gmail.com';

/** On-site API documentation portal. No external code hosting anywhere. */
export const DOCS_HREF = '/docs';

/** Landing anchors the public navigation and footer point at. */
export const LANDING_SECTIONS = {
  features: 'features',
  security: 'security',
  api: 'api',
} as const;

export type LandingSectionId = (typeof LANDING_SECTIONS)[keyof typeof LANDING_SECTIONS];

/** Path of the page that owns the anchor targets above. */
export const LANDING_PATH = '/';

/** Keeps nav hrefs and their anchor ids from drifting apart. */
const anchorHref = (section: LandingSectionId) => `/${'#'}${section}`;

/**
 * Navigation offered by the public header. `anchor` is the element id used
 * when the visitor is already on the page that owns it; `href` is the
 * destination from every other page.
 */
export type PublicNavItem = {
  labelKey: string;
  href: string;
  anchor?: LandingSectionId;
};

export const PUBLIC_NAV: PublicNavItem[] = [
  {
    labelKey: 'landing.nav.features',
    href: anchorHref(LANDING_SECTIONS.features),
    anchor: LANDING_SECTIONS.features,
  },
  {
    labelKey: 'landing.nav.security',
    href: anchorHref(LANDING_SECTIONS.security),
    anchor: LANDING_SECTIONS.security,
  },
  {
    labelKey: 'landing.nav.api',
    href: anchorHref(LANDING_SECTIONS.api),
    anchor: LANDING_SECTIONS.api,
  },
  { labelKey: 'landing.nav.docs', href: DOCS_HREF },
];

/** Legal column of the shared footer. */
export const LEGAL_NAV = [
  { href: '/legal/privacy', labelKey: 'landing.footer.legalLinks.privacy' },
  { href: '/legal/terms', labelKey: 'landing.footer.legalLinks.terms' },
  { href: '/legal/security', labelKey: 'landing.footer.legalLinks.security' },
] as const;

export function prefersReducedMotion(): boolean {
  if (typeof window === 'undefined') return false;
  return typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false;
}

/** Smooth-scroll to an in-page section, honouring reduced-motion. */
export function scrollToId(id: string) {
  if (typeof document === 'undefined') return;
  const el = document.getElementById(id);
  if (!el) return;
  el.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
}

export function scrollToTop() {
  if (typeof window === 'undefined') return;
  window.scrollTo({ top: 0, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
}
