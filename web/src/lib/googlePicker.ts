/**
 * Loads the Google Picker API once per page and opens a file/folder picker
 * configured from the backend `picker-config` endpoint.
 *
 * Scope model: with `drive.file` the app only sees files it created or that the
 * user explicitly hands over through this Picker. The access token and
 * developer key come from the backend response; nothing is hardcoded here.
 *
 * Embed model: the Picker is no longer shown through its own `setVisible(true)`
 * dialog (which mounts Google's chrome inside our page). Instead we ask the
 * builder for a `toUri()` URL and let the caller render it inside an `<iframe>`
 * of our own — a frame we fully control. The Picker still drives the selection
 * callback, so callers keep receiving the same `string[]` of ids.
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

/** Chrome options shared by the URI-only and interactive picker helpers. */
export type PickerChromeOptions = {
  /** Locale used for the Picker chrome, e.g. `id` or `en`. */
  locale?: string;
  /** Dialog title shown at the top of the Picker. */
  title?: string;
};

export type OpenPickerOptions = PickerChromeOptions & {
  /**
   * Receives the embeddable Picker URI so the caller can mount it in a frame
   * it owns. Required in embed mode — without it nothing is rendered.
   */
  onUri: (uri: string) => void;
};

type PickerLike = {
  PickerBuilder: new () => google.picker.PickerBuilder;
  DocsView: new (viewId?: string) => google.picker.DocsView;
  Feature: typeof google.picker.Feature;
  Action: typeof google.picker.Action;
};

/** Resolves the gapi/picker namespace once the loader script is ready. */
async function resolvePicker(): Promise<PickerLike> {
  await loadGapiScript();
  const gapi = window.gapi;
  const picker = window.google?.picker ?? gapi?.picker;
  if (!gapi?.picker || !picker) {
    throw new Error('Google Picker module is not available');
  }
  return picker as unknown as PickerLike;
}

/**
 * Builds the Picker instance configured from `config`, returning both the
 * builder (so callers can derive its embed URI) and the live `Picker`.
 */
function buildPicker(
  picker: PickerLike,
  config: PickerConfig,
  opts: PickerChromeOptions,
  onResponse: (data: google.picker.ResponseObject) => void,
): google.picker.PickerBuilder {
  const builder = new picker.PickerBuilder();

  const view = new picker.DocsView()
    .setIncludeFolders(true)
    .setSelectFolderEnabled(true);
  if (config.root_folder_id) view.setParent(config.root_folder_id);

  builder
    .addView(view)
    .enableFeature(picker.Feature.MULTISELECT_ENABLED)
    .setOAuthToken(config.access_token)
    .setCallback(onResponse);

  if (config.developer_key) builder.setDeveloperKey(config.developer_key);
  if (config.app_id) builder.setAppId(config.app_id);
  if (opts.locale) builder.setLocale(opts.locale);
  if (opts.title) builder.setTitle(opts.title);

  return builder;
}

/**
 * Resolves the embeddable Picker URI for the given account config.
 *
 * The returned URL is meant to be placed in an `<iframe>` the app owns. The
 * selection callback still fires on the shared `google.picker` instance, so
 * callers must keep a Picker alive (see `openGooglePicker`) to receive it.
 */
export async function getGooglePickerUri(
  config: PickerConfig,
  opts: PickerChromeOptions = {},
): Promise<string> {
  const picker = await resolvePicker();
  const builder = buildPicker(picker, config, opts, () => {});

  // `toUri` is the documented embed entry point but is absent from the minimal
  // ambient typings, so read it through a narrow, optional cast.
  const toUri = (builder as unknown as { toUri?: () => string }).toUri;
  if (typeof toUri !== 'function') {
    throw new Error('Google Picker embed URI is not available');
  }
  const uri = toUri.call(builder);
  if (!uri) throw new Error('Google Picker returned an empty embed URI');
  return uri;
}

/**
 * Opens the Google Picker for the given account config in embed mode.
 *
 * Instead of mounting Google's own dialog, this hands the embeddable URI to
 * `opts.onUri` so the caller can render it in an iframe, and resolves with the
 * array of selected Drive file/folder ids — or an empty array when the user
 * cancels (either through Google's own CANCEL action or programmatically).
 * Rejects on loader/script failures so the caller can surface a toast.
 *
 * `onCancel` receives an imperative `cancel()` trigger. The caller wires it to
 * the frame's close button/backdrop/ESC so dismissing the app's modal resolves
 * the promise with `[]` and no request is sent.
 */
export async function openGooglePicker(
  config: PickerConfig,
  opts: OpenPickerOptions,
  onCancel?: (cancel: () => void) => void,
): Promise<string[]> {
  const picker = await resolvePicker();

  return new Promise<string[]>((resolve, reject) => {
    let settled = false;
    const settle = (ids: string[]) => {
      if (settled) return;
      settled = true;
      resolve(ids);
    };

    // Expose an imperative cancel so the caller can abort when the modal that
    // hosts the iframe is dismissed before a selection is made.
    onCancel?.(() => settle([]));

    const builder = buildPicker(picker, config, opts, (data) => {
      if (data.action === picker.Action.PICKED) {
        const ids = (data.docs ?? [])
          .map((doc) => doc.id)
          .filter((id): id is string => Boolean(id));
        settle(ids);
      } else if (data.action === picker.Action.CANCEL) {
        settle([]);
      }
    });

    // `toUri` is the documented embed entry point but is absent from the
    // minimal ambient typings, so read it through a narrow, optional cast.
    const toUri = (builder as unknown as { toUri?: () => string }).toUri;

    try {
      if (typeof toUri !== 'function') {
        throw new Error('Google Picker embed URI is not available');
      }
      const uri = toUri.call(builder);
      if (!uri) throw new Error('Google Picker returned an empty embed URI');
      // Keep the built Picker alive: it owns the callback channel the iframe
      // posts to. We never call `setVisible(true)` — the app renders the frame.
      builder.build();
      opts.onUri(uri);
    } catch (e) {
      if (!settled) reject(e instanceof Error ? e : new Error('Failed to open Google Picker'));
    }
  });
}
