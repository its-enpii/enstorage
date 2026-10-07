/**
 * Minimal ambient typings for the Google Picker + gapi loader.
 *
 * The Picker script (`https://apis.google.com/js/api.js`) is loaded lazily at
 * runtime, so these declarations describe only the surface we actually touch.
 * `window.gapi` / `window.google` are optional: they are undefined until the
 * loader script resolves.
 */

declare namespace google.picker {
  enum Action {
    CANCEL = 'cancel',
    PICKED = 'picked',
  }

  enum Feature {
    MULTISELECT_ENABLED = 'MULTISELECT_ENABLED',
    NAV_HIDDEN = 'NAV_HIDDEN',
    SIMPLE_UPLOAD_ENABLED = 'SIMPLE_UPLOAD_ENABLED',
    SUPPORT_DRIVES = 'SUPPORT_DRIVES',
  }

  /** A single file/folder chosen by the user. */
  interface DocumentObject {
    id: string;
    name?: string;
    mimeType?: string;
    url?: string;
    [key: string]: unknown;
  }

  interface ResponseObject {
    action: string;
    docs?: DocumentObject[];
    viewToken?: string[];
  }

  class DocsView {
    constructor(viewId?: string);
    setIncludeFolders(value: boolean): DocsView;
    setSelectFolderEnabled(value: boolean): DocsView;
    setParent(parentId: string): DocsView;
    setMode(mode: string): DocsView;
    setMimeTypes(mimeTypes: string): DocsView;
  }

  class PickerBuilder {
    addView(view: DocsView): PickerBuilder;
    setOAuthToken(token: string): PickerBuilder;
    setDeveloperKey(key: string): PickerBuilder;
    setAppId(appId: string): PickerBuilder;
    setCallback(callback: (data: ResponseObject) => void): PickerBuilder;
    setLocale(locale: string): PickerBuilder;
    setTitle(title: string): PickerBuilder;
    enableFeature(feature: Feature | string): PickerBuilder;
    build(): Picker;
  }

  class Picker {
    setVisible(visible: boolean): void;
    dispose(): void;
    isVisible(): boolean;
  }
}

interface GapiClient {
  load(api: string): void;
  load(api: string, callback: () => void): void;
  picker: {
    PickerBuilder: typeof google.picker.PickerBuilder;
    DocsView: typeof google.picker.DocsView;
    Feature: typeof google.picker.Feature;
    Action: typeof google.picker.Action;
  };
}

interface Window {
  gapi?: GapiClient;
  google?: { picker?: typeof google.picker };
}
