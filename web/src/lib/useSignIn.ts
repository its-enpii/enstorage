'use client';

import { useCallback } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/components/AuthProvider';

/**
 * Shared "Masuk / Dashboard" behaviour for every public surface (header CTA,
 * hero CTA). Signed-in visitors go to the dashboard; everyone else goes to the
 * login page, where they can pick email + password or Google sign-in.
 */
export function useSignIn() {
  const { user } = useAuth();
  const router = useRouter();

  const signIn = useCallback(() => {
    router.push(user ? '/files' : '/login');
  }, [user, router]);

  return { user, signIn };
}
