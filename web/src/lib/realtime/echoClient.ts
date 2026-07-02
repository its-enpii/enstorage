/**
 * Reverb WS client (Pusher protocol) — singleton.
 *
 * Backend broadcasts on:
 *   private-client.{client_key}.folder.{folder_id|root}   → file events
 *   private-folder.{user_id}.{folder_id|root}             → folder events
 *
 * (Pusher auto-prefixes `private-` on the wire; channel names below
 * stay without the prefix.)
 *
 * Frontend uses one connection per tab. Connection lifecycle is owned
 * by RealtimeProvider — this file only provides the factory + cleanup
 * helpers.
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Augment global Window so pusher-js (loaded as side-effect import)
// gets pulled into the bundle. Otherwise the Pusher global is not set
// and Echo fails to construct.
declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

if (typeof window !== 'undefined') {
  window.Pusher = Pusher;
}

export type RealtimeConfig = {
  wsHost: string;
  wsPort: number;
  wssPort?: number;
  forceTLS: boolean;
  appKey: string;
  authEndpoint: string;
  token: string;
};

// Echo's TS types are strict about the broadcaster shape — we always
// use `reverb` so cast the instance to a permissive type that exposes
// just the methods we use.
type EchoInstance = {
  private: (channel: string) => {
    listen: (event: string, cb: (payload: unknown) => void) => unknown;
    stopListening: (event: string) => unknown;
  };
  leave: (channel: string) => unknown;
  disconnect: () => unknown;
  connector?: {
    pusher?: {
      connection?: {
        bind: (e: string, h: (s: { current: string }) => void) => void;
        unbind: (e: string, h: (s: { current: string }) => void) => void;
      };
    };
  };
};

let _instance: EchoInstance | null = null;
let _instanceKey: string | null = null;

/**
 * Get (or build) the singleton Echo instance for the given config.
 * Recreates on token change — same token returns cached.
 *
 * `getEcho` is SSR-safe: on the server it returns a stub so React tree
 * construction doesn't crash in `next build`.
 */
export function getEcho(cfg: RealtimeConfig): EchoInstance {
  if (typeof window === 'undefined') {
    // SSR — throw nothing, return a placeholder instance just to satisfy
    // types. RealtimeProvider never runs on the server in practice.
    return _build(cfg);
  }

  const key = `${cfg.appKey}:${cfg.wsHost}:${cfg.wsPort}:${cfg.token}`;
  if (_instance && _instanceKey === key) {
    return _instance;
  }
  if (_instance) {
    // Token rotated (e.g. after /auth/login) — kill old, build new.
    safeDisconnect(_instance);
  }
  _instance = _build(cfg);
  _instanceKey = key;
  return _instance;
}

function _build(cfg: RealtimeConfig): EchoInstance {
  // Cast to our permissive EchoInstance type — the constructor's strict
  // generic types don't fit runtime usage.
  const echo = new Echo({
    broadcaster: 'reverb',
    key: cfg.appKey,
    wsHost: cfg.wsHost,
    wsPort: cfg.wsPort,
    wssPort: cfg.wssPort ?? 443,
    forceTLS: cfg.forceTLS,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: cfg.authEndpoint,
    // Bearer matches AuthApiKey middleware priority (Bearer → API key).
    auth: { headers: { Authorization: `Bearer ${cfg.token}` } },
  });
  return echo as unknown as EchoInstance;
}

function safeDisconnect(echo: EchoInstance): void {
  try {
    echo.disconnect();
  } catch {
    // ignore — disconnect during unload can throw
  }
}

/**
 * Tear down the singleton. Use from RealtimeProvider on logout / token
 * expired (`AUTH_INVALID_EVENT`) or unmount.
 */
export function disconnectRealtime(): void {
  if (_instance) {
    safeDisconnect(_instance);
    _instance = null;
    _instanceKey = null;
  }
}

/**
 * Subscribe helper. Wraps `echo.private(channel).listen(...)` so callers
 * don't have to know the channel routing shape.
 */
export function subscribeToChannel(
  echo: EchoInstance,
  channelName: string,
  event: string,
  handler: (payload: unknown) => void,
): () => void {
  const channel = echo.private(channelName);
  channel.listen(event, handler);
  return () => {
    try {
      channel.stopListening(event);
      echo.leave(channelName);
    } catch {
      // ignore
    }
  };
}

/**
 * Read build-time config from Next.js public env. Set in `.env.local`:
 *   NEXT_PUBLIC_REVERB_APP_KEY=...
 *   NEXT_PUBLIC_REVERB_HOST=localhost
 *   NEXT_PUBLIC_REVERB_PORT=8080 (or 8083 per docker-compose in this project)
 *   NEXT_PUBLIC_REVERB_SCHEME=http
 *   NEXT_PUBLIC_API_BASE already used by api.ts (we derive authEndpoint).
 */
export function readRealtimeConfig(
  token: string,
  apiBase: string,
): RealtimeConfig | null {
  const appKey = process.env.NEXT_PUBLIC_REVERB_APP_KEY;
  const wsHost = process.env.NEXT_PUBLIC_REVERB_HOST;
  const wsPortRaw = process.env.NEXT_PUBLIC_REVERB_PORT;
  const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME ?? 'http';
  if (!appKey || !wsHost || !wsPortRaw) return null;
  const wsPort = Number.parseInt(wsPortRaw, 10);
  if (!Number.isFinite(wsPort)) return null;

  // Auth endpoint sits under the API base — Reverb's channel auth
  // route is mounted at /broadcasting/auth by Laravel 11's withRouting
  // channels: arg.
  const base = apiBase.replace(/\/$/, '');
  const authEndpoint = `${base.replace(/\/api\/v\d+$/, '')}/broadcasting/auth`;

  return {
    appKey,
    wsHost,
    wsPort,
    forceTLS: scheme === 'https',
    authEndpoint,
    token,
  };
}
