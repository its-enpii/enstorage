import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import idTranslations from '../../public/locales/id/translation.json';
import enTranslations from '../../public/locales/en/translation.json';

/**
 * localStorage key used to persist the user's locale choice.
 */
export const STORAGE_KEY = 'enstorage_locale';
/**
 * Locale used for server rendering and the first client render. Reading
 * localStorage during module evaluation (or the first render) causes a
 * hydration text mismatch (React #418) when the stored locale differs from
 * this value, so the stored locale is applied only after hydration.
 */
export const DEFAULT_LOCALE = 'id';

/**
 * Change language and persist to localStorage.
 * The I18nProvider handles syncing to user.locale on the server.
 */
export function setLocale(lang: string) {
  i18n.changeLanguage(lang);
  if (typeof localStorage !== 'undefined') {
    localStorage.setItem(STORAGE_KEY, lang);
  }
  if (typeof document !== 'undefined') {
    document.documentElement.lang = lang;
  }
}

export function getLocale(): string {
  return i18n.language || DEFAULT_LOCALE;
}

// Always initialize with DEFAULT_LOCALE so the first client render matches the
// server-rendered HTML exactly. The stored locale is applied post-hydration by
// I18nProvider's Syncer.
i18n.use(initReactI18next).init({
  resources: {
    id: { translation: idTranslations },
    en: { translation: enTranslations },
  },
  lng: DEFAULT_LOCALE,
  fallbackLng: DEFAULT_LOCALE,
  interpolation: { escapeValue: false },
});

export default i18n;
