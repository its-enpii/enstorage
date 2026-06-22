'use client';

import { Suspense, useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@/components/AuthProvider';

function CallbackContent() {
  const { t } = useTranslation();
  const router = useRouter();
  const searchParams = useSearchParams();
  const { handleGoogleCallback } = useAuth();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const token = searchParams.get('token');
    const err = searchParams.get('error');

    if (err) {
      setError(err);
      return;
    }

    if (!token) {
      setError(t('auth.callback.failed'));
      return;
    }

    handleGoogleCallback(token)
      .then(() => {
        router.replace('/files');
      })
      .catch((e) => {
        setError(e instanceof Error ? e.message : t('auth.callback.failed'));
      });
  }, [searchParams, handleGoogleCallback, router, t]);

  if (error) {
    return (
      <div className="w-full max-w-sm bg-surface rounded-card shadow-inner-glow p-inner-padding text-center">
        <div className="rounded-xl bg-error-container/30 border border-error/30 px-3 py-2 text-metadata text-error mb-5">
          {error}
        </div>
        <button
          onClick={() => router.replace('/login')}
          className="text-label-lg text-primary hover:underline"
        >
          {t('auth.reLogin')}
        </button>
      </div>
    );
  }

  return (
    <div className="w-full max-w-sm bg-surface rounded-card shadow-inner-glow p-inner-padding text-center">
      <div className="flex items-center justify-center gap-2 text-on-surface-variant">
        <svg className="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
          <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
        <span className="text-metadata">{t('auth.callback.processing')}</span>
      </div>
    </div>
  );
}

export default function AuthCallbackPage() {
  return (
    <main className="flex min-h-screen items-center justify-center px-4 bg-background">
      <Suspense
        fallback={
          <div className="w-full max-w-sm bg-surface rounded-card shadow-inner-glow p-inner-padding text-center">
            <div className="flex items-center justify-center gap-2 text-on-surface-variant">
              <svg className="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
              </svg>
            </div>
          </div>
        }
      >
        <CallbackContent />
      </Suspense>
    </main>
  );
}
