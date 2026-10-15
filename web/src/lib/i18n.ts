import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import idTranslations from '../../public/locales/id/translation.json';
import enTranslations from '../../public/locales/en/translation.json';

const STORAGE_KEY = 'enstorage_locale';
const DEFAULT_LOCALE = 'id';

/**
 * Resolve initial language synchronously from localStorage, falling back to
 * Indonesian (the original default).
 */
function getInitialLang(): string {
  if (typeof window === 'undefined') return DEFAULT_LOCALE;
  return localStorage.getItem(STORAGE_KEY) ?? DEFAULT_LOCALE;
}

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
  return i18n.language || getInitialLang();
}

i18n.use(initReactI18next).init({
  resources: {
    id: { translation: idTranslations },
    en: { translation: enTranslations },
  },
  lng: getInitialLang(),
  fallbackLng: DEFAULT_LOCALE,
  interpolation: { escapeValue: false },
});

/**
 * Keep `document.documentElement.lang` in sync with the language loaded from
 * storage on first paint. The server always renders `lang="id"`, so the initial
 * value must be corrected here (guarded for SSR); `setLocale` handles later
 * switches. Because React hydrates the server-rendered `<html lang="id">` and
 * re-applies that attribute during commit (which happens *after* this module
 * runs), we also observe the attribute and re-assert the active locale whenever
 * it drifts — e.g. when hydration patches it back to the default.
 */
if (typeof document !== 'undefined') {
  const syncLang = () => {
    const locale = getLocale();
    if (document.documentElement.lang !== locale) {
      document.documentElement.lang = locale;
    }
  };

  syncLang();
  i18n.on('languageChanged', syncLang);

  new MutationObserver(syncLang).observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['lang'],
  });
}

export default i18n;
