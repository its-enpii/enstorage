'use client';

import { useEffect, type ReactNode } from 'react';
import { I18nextProvider, useTranslation } from 'react-i18next';
import i18n, { setLocale, getLocale } from '@/lib/i18n';

type Props = {
  children: ReactNode;
  /**
   * Server-provided locale (from user.locale on login). When set, it
   * overrides localStorage once; subsequent changes go through setLocale().
   */
  initialLocale?: string;
};

function Syncer({ initialLocale }: { initialLocale?: string }) {
  const { i18n: i18nInstance } = useTranslation();

  // Sync to user.locale on first render if provided (e.g. after login)
  useEffect(() => {
    if (initialLocale && initialLocale !== getLocale()) {
      setLocale(initialLocale);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Sync <html lang> whenever language changes
  useEffect(() => {
    const handler = (lng: string) => {
      document.documentElement.lang = lng;
    };
    i18nInstance.on('languageChanged', handler);
    return () => { i18nInstance.off('languageChanged', handler); };
  }, [i18nInstance]);

  return null;
}

/**
 * Thin wrapper around I18nextProvider that:
 * - Initializes react-i18next once (via lib/i18n.ts)
 * - Syncs `<html lang>` with the current language
 * - Optionally overrides locale from user.locale on login
 */
export function I18nProvider({ children, initialLocale }: Props) {
  return (
    <I18nextProvider i18n={i18n}>
      <Syncer initialLocale={initialLocale} />
      {children}
    </I18nextProvider>
  );
}
