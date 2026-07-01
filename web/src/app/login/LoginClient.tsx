'use client';

import { useState } from 'react';
import { Cloud } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@/components/AuthProvider';
import { Button } from '@/components/Button';
import { usePageTitle } from '@/lib/usePageTitle';

export default function LoginClient() {
  const { t } = useTranslation();
  const { googleLogin } = useAuth();
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  usePageTitle(t('auth.login.title'));

  async function handleGoogle() {
    setError(null);
    setLoading(true);
    try {
      await googleLogin();
    } catch (err) {
      setError(err instanceof Error ? err.message : t('auth.login.failed'));
      setLoading(false);
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center px-4 bg-background">
      <div className="w-full max-w-sm bg-surface p-inner-padding rounded-card shadow-inner-glow">
        <div className="flex items-center gap-3 mb-2">
          <div className="w-12 h-12 rounded-2xl bg-primary-container text-on-primary-container flex items-center justify-center shrink-0">
            <Cloud className="!text-3xl fill" />
          </div>
          <div>
            <h1 className="font-display text-headline-lg-mobile text-on-surface">
              {t('auth.login.title')}
            </h1>
            <p className="text-metadata text-on-surface-variant">
              {t('auth.login.subtitle')}
            </p>
          </div>
        </div>

        <div className="mt-8">
          {error && (
            <div className="rounded-xl bg-error-container/30 border border-error/30 px-3 py-2 text-metadata text-error mb-5">
              {error}
            </div>
          )}

          <Button
            onClick={handleGoogle}
            loading={loading}
            fullWidth
            size="lg"
            leftIcon={
              <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
              </svg>
            }
          >
            {loading ? t('auth.login.googleLoading') : t('auth.login.googleButton')}
          </Button>
        </div>
      </div>
    </main>
  );
}