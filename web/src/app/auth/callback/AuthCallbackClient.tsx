'use client';

import { Suspense, useEffect, useState } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@/components/AuthProvider';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { Alert } from '@/components/Alert';
import { Spinner } from '@/components/Spinner';
import { usePageTitle } from '@/lib/usePageTitle';

function CallbackContent() {
  const { t } = useTranslation();
  const router = useRouter();
  const searchParams = useSearchParams();
  const { handleGoogleCallback } = useAuth();
  const [error, setError] = useState<string | null>(null);
  usePageTitle(t('auth.callback.title'));

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
      <Card className="w-full max-w-sm text-center">
        <Alert className="mb-5 !text-metadata">{error}</Alert>
        <Button variant="link" onClick={() => router.replace('/login')}>
          {t('auth.reLogin')}
        </Button>
      </Card>
    );
  }

  return (
    <Card className="w-full max-w-sm text-center">
      <div className="flex items-center justify-center gap-2 text-on-surface-variant">
        <Spinner size="sm" />
        <span className="text-metadata">{t('auth.callback.processing')}</span>
      </div>
    </Card>
  );
}

export default function AuthCallbackClient() {
  return (
    <main className="flex min-h-screen items-center justify-center px-4 bg-background">
      <Suspense
        fallback={
          <Card className="w-full max-w-sm flex items-center justify-center text-center">
            <Spinner />
          </Card>
        }
      >
        <CallbackContent />
      </Suspense>
    </main>
  );
}