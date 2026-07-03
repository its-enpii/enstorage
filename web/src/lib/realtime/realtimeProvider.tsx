'use client';

import {
  createContext,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { useParams } from 'next/navigation';
import { AUTH_INVALID_EVENT, getToken } from '@/lib/api';
import { useAuth } from '@/components/AuthProvider';
import {
  disconnectRealtime,
  getEcho,
  readRealtimeConfig,
  subscribeToChannel,
} from './echoClient';
import { applyEvent, parseRealtimePayload, type StoreMutators } from './handlers';

export type ConnectionState = 'connecting' | 'connected' | 'reconnecting' | 'offline' | 'idle';

type RealtimeContextValue = {
  state: ConnectionState;
  lastError: string | null;
  /**
   * Bind the currently-mounted FilesStoreProvider mutators so the
   * realtime listener can dispatch into them. Called by
   * FilesStoreBinder (mounted inside FilesStoreProvider). Passing a
   * fresh visibleFolderIds Set on every render is fine — the ref only
   * reads the latest value.
   */
  bindFilesStore: (mutators: StoreMutators | null, visibleFolderIds: Set<string>) => void;
};

const RealtimeContext = createContext<RealtimeContextValue | null>(null);

export function useRealtime(): RealtimeContextValue {
  const ctx = useContext(RealtimeContext);
  if (!ctx) throw new Error('useRealtime must be inside RealtimeProvider');
  return ctx;
}

const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8080/api/v1';

// Pusher event names used as `.listen()` arguments. Laravel Echo's
// EventFormatter prepends `App.Events.` and replaces `.` → `\` when the
// input is a bare basename (it special-cases inputs starting with `.`
// or `\` to skip the prefix). Passing the FQCN `App\Events\X` here would
// double-prefix and produce `App\Events\App\Events\X`, which never
// matches the server-side broadcast name. Use the short class name and
// let the formatter build the wire name.
const FILE_EVENTS = [
  'FileUploadedBroadcast',
  'FileUploadFailedBroadcast',
  'FileMovedBroadcast',
  'FileDeletedBroadcast',
  'FileUpdatedBroadcast',
];
const FOLDER_EVENTS = [
  'FolderCreatedBroadcast',
  'FolderDeletedBroadcast',
  'FolderRenamedBroadcast',
  'FolderMovedBroadcast',
];

export function RealtimeProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const params = useParams();
  // catch-all [[...path]] → folder id is the last path segment (root = null).
  const path = (params?.path as string[] | undefined) ?? [];
  const folderId = path[path.length - 1] ?? null;

  const [state, setState] = useState<ConnectionState>('idle');
  const [lastError, setLastError] = useState<string | null>(null);
  const storeRef = useRef<StoreMutators | null>(null);
  const visibleFolderIdsRef = useRef<Set<string>>(new Set());

  const bindFilesStore: RealtimeContextValue['bindFilesStore'] = (mutators, visibleFolderIds) => {
    storeRef.current = mutators;
    visibleFolderIdsRef.current = visibleFolderIds;
  };

  const ctxValue = useMemo<RealtimeContextValue>(
    () => ({ state, lastError, bindFilesStore }),
    [state, lastError],
  );

  // Disconnect on auth invalidation (401 from any API call).
  useEffect(() => {
    const onInvalid = () => {
      disconnectRealtime();
      setState('idle');
      setLastError(null);
      storeRef.current = null;
    };
    window.addEventListener(AUTH_INVALID_EVENT, onInvalid);
    return () => window.removeEventListener(AUTH_INVALID_EVENT, onInvalid);
  }, []);

  // Connect + subscribe loop. Recreates on (user, folderId) change —
  // WS subscription is now per-user (not per-folder), so the WS layer
  // doesn't churn on navigation, but the dispatch lambda still needs the
  // fresh `folderId` to filter via matchesView(). Token is read inside
  // the effect via getToken() and refreshed by the AUTH_INVALID_EVENT
  // listener above on logout.
  useEffect(() => {
    const token = getToken();
    if (!user || !token) {
      setState('idle');
      return;
    }

    const cfg = readRealtimeConfig(token, API_BASE);
    if (!cfg) {
      setState('offline');
      setLastError('Realtime env vars missing (NEXT_PUBLIC_REVERB_*)');
      return;
    }

    setState('connecting');
    let echo: ReturnType<typeof getEcho>;
    try {
      echo = getEcho(cfg);
    } catch (e) {
      setState('offline');
      setLastError(e instanceof Error ? e.message : 'Echo init failed');
      return;
    }

    // Bind to Pusher's connection state. Echo exposes this via its
    // `connector.pusher` reference (typed as `any` in laravel-echo).
    const cleanupFns: Array<() => void> = [];
    // Echo: echo.connector = PusherConnector, .pusher = Pusher instance.
    const connector = (echo as unknown as {
      connector?: {
        pusher?: {
          connection?: { bind: (e: string, h: (s: { current: string }) => void) => void; unbind: (e: string, h: (s: { current: string }) => void) => void };
          bind_global?: (h: (eventName: unknown, data: unknown) => void) => void;
          unbind_global?: (h: (eventName: unknown, data: unknown) => void) => void;
        };
      };
    }).connector;
    const pusherInstance = connector?.pusher;
    const connection = pusherInstance?.connection;
    // TEMP DEBUG — surface actual runtime structure so we stop guessing.
    console.log('[rt-debug] echo keys=', Object.keys(echo as object), '| connector keys=', connector ? Object.keys(connector) : null, '| connector.pusher keys=', pusherInstance ? Object.keys(pusherInstance as object) : null, '| has bind_global=', typeof (pusherInstance as { bind_global?: unknown } | undefined)?.bind_global, '| has connection=', !!connection);
    const onStateChange = ({ current }: { current: string }) => {
      if (current === 'connected') setState('connected');
      else if (current === 'unavailable' || current === 'disconnected') setState('reconnecting');
      else if (current === 'failed') setState('offline');
    };
    if (connection) {
      connection.bind('state_change', onStateChange);
    }
    // TEMP DEBUG — raw frame listener, BEFORE Echo's per-event filter.
    // Pusher's bind_global callback signature is (eventName, data).
    const onRaw = (eventName: unknown, data: unknown) => {
      if (typeof eventName !== 'string') return;
      if (eventName.startsWith('pusher:') || eventName.startsWith('pusher_internal:')) return;
      console.log('[rt-raw] event=', JSON.stringify(eventName), '| data=', data);
    };
    if (typeof pusherInstance?.bind_global === 'function') {
      pusherInstance.bind_global(onRaw);
      cleanupFns.push(() => pusherInstance.unbind_global?.(onRaw));
    } else {
      console.log('[rt-raw] bind_global not available on echo.connector.pusher');
    }

    // Subscribe.
    //
    // One private channel per user: `user-{userId}`. Backend broadcasts
    // every file + folder event for the user on this single channel
    // (no folder scope). Clients filter by the payload's `folder_id` /
    // `parent_id` against their current view (`matchesView()` in
    // handlers.ts). Folder navigation no longer churns the WS subscription.
    const unsubs: Array<() => void> = [];
    const userChannel = `user-${user.id}`;
    // Channel name uses DASH between the prefix and the user id — same
    // format the backend `ReverbChannel::user()` emits and the closure
    // in routes/channels.php matches. Using a dot here would make
    // Laravel fail to match the closure pattern and `/broadcasting/auth`
    // would return 403.

    const dispatch = (eventName: string) => (payload: unknown) => {
      // TEMP DEBUG — hapus setelah fix WS UI update
      console.log('[rt] event:', eventName, '| payload:', payload, '| store:', !!storeRef.current, '| folderId:', folderId);
      const ev = parseRealtimePayload(eventName, payload);
      if (!ev) {
        console.log('[rt] parseRealtimePayload returned null for', eventName);
        return;
      }
      const applied = applyEvent(ev, {
        store: storeRef.current,
        currentFolderId: folderId,
        visibleFolderIds: visibleFolderIdsRef.current,
      });
      console.log('[rt] applyEvent returned:', applied, '| type:', ev.type);
    };

    for (const name of [...FILE_EVENTS, ...FOLDER_EVENTS]) {
      unsubs.push(subscribeToChannel(echo, userChannel, name, dispatch(name)));
    }

    // Eagerly mark "connected" if we made it this far without throwing.
    setTimeout(() => setState((s) => (s === 'connecting' ? 'connected' : s)), 250);

    return () => {
      try {
        if (connection) connection.unbind('state_change', onStateChange);
      } catch {
        // ignore
      }
      for (const u of unsubs) {
        try {
          u();
        } catch {
          // ignore
        }
      }
      for (const u of cleanupFns) {
        try { u(); } catch { /* ignore */ }
      }
    };
  }, [user, folderId]);

  return (
    <RealtimeContext.Provider value={ctxValue}>
      {children}
    </RealtimeContext.Provider>
  );
}

/**
 * Mounted inside `FilesStoreProvider`. Wires that store's mutators +
 * visible folder ids into RealtimeProvider's refs so incoming WS
 * events can dispatch. Renders nothing.
 */
export function FilesStoreBinder({
  mutators,
  visibleFolderIds,
}: {
  mutators: StoreMutators;
  visibleFolderIds: Set<string>;
}) {
  const { bindFilesStore } = useRealtime();
  useEffect(() => {
    bindFilesStore(mutators, visibleFolderIds);
    return () => bindFilesStore(null, new Set());
  }, [bindFilesStore, mutators, visibleFolderIds]);
  return null;
}