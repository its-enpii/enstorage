import type { Metadata } from 'next';
import id from '../../public/locales/id/translation.json';
import en from '../../public/locales/en/translation.json';

export type Locale = 'id' | 'en';
export const DEFAULT_LOCALE: Locale = 'id';
const SUFFIX = 'EnStorage';

const trees: Record<Locale, Record<string, unknown>> = {
  id: id as unknown as Record<string, unknown>,
  en: en as unknown as Record<string, unknown>,
};

/**
 * Dot-path lookup against the bundled locale JSON. Returns the literal key
 * if any segment is missing (mirrors i18next fallback behavior for unknown
 * keys so the title is at least informative).
 */
function resolveKey(locale: Locale, key: string): string {
  let cur: unknown = trees[locale];
  for (const p of key.split('.')) {
    if (cur && typeof cur === 'object' && p in (cur as object)) {
      cur = (cur as Record<string, unknown>)[p];
    } else {
      return key;
    }
  }
  return typeof cur === 'string' ? cur : key;
}

/**
 * Build a Next.js `metadata.title` string for a page from an i18n key.
 * Format matches `usePageTitle`: `"<resolved> · <suffix>"`.
 *
 * Server-only because it imports the locale JSONs as modules. Do not import
 * from a `'use client'` file — Next 15 will hard-error on metadata exports
 * there. The client `usePageTitle` hook remains responsible for locale
 * reactivity and dynamic data (folder name in catch-all /files).
 */
export function pageTitle(
  key: string,
  opts: { locale?: Locale; suffix?: string } = {},
): Pick<Metadata, 'title'> {
  const locale = opts.locale ?? DEFAULT_LOCALE;
  const suffix = opts.suffix ?? SUFFIX;
  const resolved = resolveKey(locale, key);
  return { title: resolved === suffix ? suffix : `${resolved} · ${suffix}` };
}