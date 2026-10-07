/**
 * Loads the Google Picker API once per page and opens a file/folder picker
 * configured from the backend `picker-config` endpoint.
 *
 * Scope model: with `drive.file` the app only sees files it created or that the
 * user explicitly hands over through this Picker. The access token and
 * developer key come from the backend response; nothing is hardcoded here.
 */

import type { PickerConfig } from '@/lib/api';

const GAPI_SRC = 'https://apis.google.com/js/api.js';

let loaderPromise: Promise<void> | null = null;

/** Injected `<script>` for the gapi loader, resolved once. */
function loadGapiScript(): Promise<void> {
  if (typeof window === 'undefined') {
    return Promise.reject(new Error('Google Picker is only available in the browser'));
  }
  if (window.gapi?.picker) return Promise.resolve();
  if (loaderPromise) return loaderPromise;

  loaderPromise = new Promise<void>((resolve, reject) => {
    const existing = document.querySelector<HTMLScriptElement>(`script[src="${GAPI_SRC}"]`);
    const onReady = () => {
      if (!window.gapi) {
        reject(new Error('Google API loader failed to initialise'));
        return;
      }
      window.gapi.load('picker', () => {
        if (window.gapi?.picker) resolve();
        else reject(new Error('Google Picker module failed to load'));
      });
    };

    if (existing) {
      if (window.gapi) onReady();
      else {
        existing.addEventListener('load', onReady, { once: true });
        existing.addEventListener('error', () => reject(new Error('Google API loader failed to load')), { once: true });
      }
      return;
    }

    const script = document.createElement('script');
    script.src = GAPI_SRC;
    script.async = true;
    script.defer = true;
    script.addEventListener('load', onReady, { once: true });
    script.addEventListener('error', () => reject(new Error('Google API loader failed to load')), { once: true });
    document.head.appendChild(script);
  });

  // Allow a later retry if the first attempt fails.
  loaderPromise.catch(() => {
    loaderPromise = null;
  });

  return loaderPromise;
}

export type OpenPickerOptions = {
  /** Locale used for the Picker chrome, e.g. `id` or `en`. */
  locale?: string;
  /** Dialog title shown at the top of the Picker. */
  title?: string;
};

/**
 * Opens the Google Picker for the given account config.
 *
 * Resolves with the array of selected Drive file/folder ids, or an empty array
 * when the user cancels. Rejects on loader/script failures so the caller can
 * surface a toast.
 */
export async function openGooglePicker(
  config: PickerConfig,
  opts: OpenPickerOptions = {},
): Promise<string[]> {
  await loadGapiScript();

  const gapi = window.gapi;
  const picker = window.google?.picker ?? gapi?.picker;
  if (!gapi?.picker || !picker) {
    throw new Error('Google Picker module is not available');
  }

  return new Promise<string[]>((resolve, reject) => {
    let settled = false;

    const builder = new picker.PickerBuilder();

    const view = new picker.DocsView()
      .setIncludeFolders(true)
      .setSelectFolderEnabled(true);
    if (config.root_folder_id) view.setParent(config.root_folder_id);

    builder
      .addView(view)
      .enableFeature(picker.Feature.MULTISELECT_ENABLED)
      .setOAuthToken(config.access_token)
      .setCallback((data: google.picker.ResponseObject) => {
        if (data.action === picker.Action.PICKED) {
          settled = true;
          const ids = (data.docs ?? [])
            .map((doc) => doc.id)
            .filter((id): id is string => Boolean(id));
          resolve(ids);
        } else if (data.action === picker.Action.CANCEL) {
          settled = true;
          resolve([]);
        }
      });

    if (config.developer_key) builder.setDeveloperKey(config.developer_key);
    if (config.app_id) builder.setAppId(config.app_id);
    if (opts.locale) builder.setLocale(opts.locale);
    if (opts.title) builder.setTitle(opts.title);

    try {
      const dialog = builder.build();
      dialog.setVisible(true);
    } catch (e) {
      if (!settled) reject(e instanceof Error ? e : new Error('Failed to open Google Picker'));
    }
  });
}
