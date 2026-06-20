'use client';

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useLayoutEffect,
  useState,
  type ReactNode,
} from 'react';
import { apiRequest, ApiError, getToken, setToken, type User } from '@/lib/api';
import { setLocale } from '@/lib/i18n';

const USER_CACHE_KEY = 'enstorage_user';

type AuthContextValue = {
  user: User | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (name: string, email: string, password: string, passwordConfirmation: string) => Promise<void>;
  logout: () => Promise<void>;
  refresh: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

function readCachedUser(): User | null {
  if (typeof window === 'undefined') return null;
  try {
    const raw = window.localStorage.getItem(USER_CACHE_KEY);
    return raw ? (JSON.parse(raw) as User) : null;
  } catch {
    return null;
  }
}

function writeCachedUser(u: User | null) {
  if (typeof window === 'undefined') return;
  try {
    if (u) window.localStorage.setItem(USER_CACHE_KEY, JSON.stringify(u));
    else window.localStorage.removeItem(USER_CACHE_KEY);
  } catch {
    // ignore
  }
}

export function AuthProvider({ children }: { children: ReactNode }) {
  // Always start with null/loading on both server and client to avoid hydration
  // mismatch. After mount, useLayoutEffect hydrates from localStorage.
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  // Synchronous hydrate from cache before paint (client only).
  useLayoutEffect(() => {
    if (!getToken()) {
      setUser(null);
      setLoading(false);
      return;
    }
    const cached = readCachedUser();
    if (cached) {
      setUser(cached);
      setLoading(false);
    }
  }, []);

  const fetchMe = useCallback(async (): Promise<User | null> => {
    if (!getToken()) return null;
    try {
      const u = await apiRequest<User>('/auth/me?with_counts=1');
      writeCachedUser(u);
      return u;
    } catch (e) {
      // Only clear token on explicit 401 (token invalid/expired/revoked).
      // Other errors (network, 5xx, timeout) keep the token so user can retry.
      if (e instanceof ApiError && e.status === 401) {
        setToken(null);
        writeCachedUser(null);
      }
      return null;
    }
  }, []);

  useEffect(() => {
    // Background revalidate. Don't flip loading=true on revalidate.
    if (!getToken()) {
      setLoading(false);
      return;
    }
    (async () => {
      const u = await fetchMe();
      if (u) setUser(u);
      setLoading(false);
    })();
  }, [fetchMe]);

  const login = useCallback(async (email: string, password: string) => {
    const res = await apiRequest<{ user: User; token: string }>('/auth/login', {
      method: 'POST',
      body: { email, password },
      auth: false,
    });
    setToken(res.token);
    setUser(res.user);
    writeCachedUser(res.user);
    // Sync locale from server (default: 'id')
    if (res.user.locale) setLocale(res.user.locale);
  }, []);

  const register = useCallback(
    async (name: string, email: string, password: string, passwordConfirmation: string) => {
      const res = await apiRequest<{ user: User; token: string }>('/auth/register', {
        method: 'POST',
        body: { name, email, password, password_confirmation: passwordConfirmation },
        auth: false,
      });
      setToken(res.token);
      setUser(res.user);
      writeCachedUser(res.user);
      if (res.user.locale) setLocale(res.user.locale);
    },
    [],
  );

  const logout = useCallback(async () => {
    try {
      await apiRequest<null>('/auth/logout', { method: 'POST' });
    } catch {
      // ignore
    }
    setToken(null);
    setUser(null);
    writeCachedUser(null);
    // Reset to default on logout (localStorage still holds it for guest)
  }, []);

  const refresh = useCallback(async () => {
    const u = await fetchMe();
    if (u) setUser(u);
  }, [fetchMe]);

  return (
    <AuthContext.Provider value={{ user, loading, login, register, logout, refresh }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
}