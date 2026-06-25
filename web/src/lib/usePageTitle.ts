'use client';

import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';

/**
 * Set `document.title` to "<title> · <suffix>" on mount and whenever the
 * i18n language changes (so locale switches update the tab title without
 * a page reload).
 *
 * - Pass an i18n key (e.g. "files.title") and it will be resolved via `t()`.
 * - Pass a raw string and it will be used as-is.
 * - Pass null/undefined to reset to the bare suffix.
 *
 * SSR-safe: only mutates `document.title` inside `useEffect`, no hydration
 * mismatch.
 */
export function usePageTitle(
  title: string | null | undefined,
  suffix: string = 'EnStorage',
) {
  const { t, i18n } = useTranslation();
  // language dep ensures locale switches re-run this effect.
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  const lang = i18n.language;
  useEffect(() => {
    if (!title) {
      document.title = suffix;
      return;
    }
    const resolved = t(title);
    document.title = resolved === suffix ? suffix : `${resolved} · ${suffix}`;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [title, lang, suffix]);
}
