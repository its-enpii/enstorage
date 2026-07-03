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

// Pusher FQCN event names used as `.listen()` arguments. Echo maps
// these directly to the backend ShouldBroadcastNow events.
const FILE_EVENTS = [
  'App\\Events\\FileUploadedBroadcast',
  'App\\Events\\FileUploadFailedBroadcast',
  'App\\Events\\FileMovedBroadcast',
  'App\\Events\\FileDeletedBroadcast',
  'App\\Events\\FileUpdatedBroadcast',
];
const FOLDER_EVENTS = [
  'App\\Events\\FolderCreatedBroadcast',
  'App\\Events\\FolderDeletedBroadcast',
  'App\\Events\\FolderRenamedBroadcast',
  'App\\Events\\FolderMovedBroadcast',
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
    const pusherConnection = (echo as unknown as {
      connector?: { pusher?: { connection?: { bind: (e: string, h: (s: { current: string }) => void) => void; unbind: (e: string, h: (s: { current: string }) => void) => void } } };
    }).connector?.pusher?.connection;
    const onStateChange = ({ current }: { current: string }) => {
      if (current === 'connected') setState('connected');
      else if (current === 'unavailable' || current === 'disconnected') setState('reconnecting');
      else if (current === 'failed') setState('offline');
    };
    if (pusherConnection) {
      pusherConnection.bind('state_change', onStateChange);
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
      const ev = parseRealtimePayload(eventName, payload);
      if (!ev) return;
      applyEvent(ev, {
        store: storeRef.current,
        currentFolderId: folderId,
        visibleFolderIds: visibleFolderIdsRef.current,
      });
    };

    for (const name of [...FILE_EVENTS, ...FOLDER_EVENTS]) {
      unsubs.push(subscribeToChannel(echo, userChannel, name, dispatch(name)));
    }

    // Eagerly mark "connected" if we made it this far without throwing.
    setTimeout(() => setState((s) => (s === 'connecting' ? 'connected' : s)), 250);

    return () => {
      try {
        if (pusherConnection) pusherConnection.unbind('state_change', onStateChange);
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