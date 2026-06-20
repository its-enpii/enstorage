'use client';

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { apiRequest, ApiError } from '@/lib/api';
import { cacheGet, cacheSet } from '@/lib/cache';
import { useAuth } from '@/components/AuthProvider';

// Generic per-view store: keyed by a string the caller computes (e.g. 'starred',
// 'folders:current:{id}', 'google-accounts', 'api-keys'). Hydrates from
// localStorage synchronously before paint, then revalidates in background.

type ViewStoreContextValue<T> = {
  data: T | null;
  loading: boolean;
  error: string | null;
  revalidate: () => Promise<void>;
  setData: (updater: T | null | ((prev: T | null) => T | null)) => void;
};

export function createViewStore<T>(fetcher: () => Promise<T>, ttlMs = 5 * 60_000) {
  const Context = createContext<ViewStoreContextValue<T> | null>(null);

  function Provider({ viewKey, children }: { viewKey: string; children: ReactNode }) {
    const { user } = useAuth();
    // Read user.id from localStorage as a fallback for the initial render —
    // AuthProvider hydrates user from cache in a layout effect that runs
    // AFTER this Provider's effects, so React-state-only would yield "" here.
    const userId =
      user?.id ||
      (typeof window !== 'undefined'
        ? (() => {
            try {
              const raw = window.localStorage.getItem('enstorage_user');
              return raw ? ((JSON.parse(raw) as { id?: string }).id ?? '') : '';
            } catch {
              return '';
            }
          })()
        : '');

    // Synchronously read cache on first render so the first paint already
    // has data — no empty-state flash. SSR returns null/empty, then the
    // client picks up the cached value.
    const initialCache = useRef<{ value: T | null; read: boolean }>({ value: null, read: false });
    if (!initialCache.current.read && typeof window !== 'undefined' && userId) {
      initialCache.current = {
        value: cacheGet<T>(userId, viewKey),
        read: true,
      };
    }
    const seed = initialCache.current.value;

    const [data, setDataState] = useState<T | null>(seed);
    const [loading, setLoading] = useState(seed === null);
    const [error, setError] = useState<string | null>(null);
    const dataRef = useRef<T | null>(seed);
    dataRef.current = data;

    // Mirror data to cache on every change so optimistic mutations
    // (setData) survive navigation away & back.
    useEffect(() => {
      if (!userId || data === null) return;
      cacheSet(userId, viewKey, data, ttlMs);
    }, [userId, viewKey, data, ttlMs]);

    const revalidate = useCallback(
      async (showLoading = false) => {
        if (!userId) return;
        if (showLoading) {
          setLoading(true);
          setError(null);
        }
        try {
          const fresh = await fetcher();
          setDataState(fresh);
          cacheSet(userId, viewKey, fresh, ttlMs);
        } catch (e) {
          if (showLoading) {
            setError(e instanceof ApiError ? e.message : 'Gagal memuat.');
          }
        } finally {
          if (showLoading) setLoading(false);
        }
      },
      [userId, viewKey],
    );

    // Initial state already hydrated from cache. Kick off background revalidate
    // (or full fetch if no seed).
    useEffect(() => {
      if (!userId) return;
      if (seed !== null) {
        void revalidate(false);
      } else {
        void revalidate(true);
      }
      // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [userId, viewKey]);

    const setData = useCallback(
      (updater: T | null | ((prev: T | null) => T | null)) => {
        setDataState((prev) => {
          const next =
            typeof updater === 'function'
              ? (updater as (p: T | null) => T | null)(prev)
              : updater;
          if (next !== null) cacheSet(userId, viewKey, next, ttlMs);
          return next;
        });
      },
      [userId, viewKey],
    );

    const value: ViewStoreContextValue<T> = {
      data,
      loading,
      error,
      revalidate: () => revalidate(false),
      setData,
    };

    return <Context.Provider value={value}>{children}</Context.Provider>;
  }

  function useStore() {
    const ctx = useContext(Context);
    if (!ctx) throw new Error('useStore must be used inside Provider');
    return ctx;
  }

  return { Provider, useStore };
}
