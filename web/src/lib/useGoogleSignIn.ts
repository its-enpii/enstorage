'use client';

import { useCallback, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/components/AuthProvider';

/**
 * Shared "Masuk / Dashboard" behaviour for every public surface (header CTA,
 * hero CTA). Signed-in visitors go to the dashboard; everyone else starts the
 * Google OAuth flow and falls back to the password login page on failure.
 */
export function useGoogleSignIn() {
  const { user, googleLogin } = useAuth();
  const router = useRouter();
  const [signingIn, setSigningIn] = useState(false);

  const signIn = useCallback(async () => {
    if (user) {
      router.push('/files');
      return;
    }
    setSigningIn(true);
    try {
      await googleLogin();
    } catch {
      setSigningIn(false);
      router.push('/login');
    }
  }, [user, googleLogin, router]);

  return { user, signIn, signingIn };
}
