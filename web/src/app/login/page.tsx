'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { useAuth } from '@/components/AuthProvider';
import { Button } from '@/components/Button';
import { Field, Input } from '@/components/Input';

export default function LoginPage() {
  const { t } = useTranslation();
  const router = useRouter();
  const { login } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await login(email, password);
      router.replace('/files');
    } catch (err) {
      setError(err instanceof Error ? err.message : t('auth.login.failed'));
    } finally {
      setLoading(false);
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center px-4 bg-background">
      <div className="w-full max-w-sm bg-surface rounded-card shadow-inner-glow p-inner-padding">
        <h1 className="font-display text-headline-lg-mobile text-on-surface mb-2">
          {t('auth.login.title')}
        </h1>
        <p className="text-metadata text-on-surface-variant mb-8">
          {t('auth.login.subtitle')}
        </p>

        <form onSubmit={onSubmit} className="space-y-5">
          <Field label={t('auth.login.email')} htmlFor="email">
            <Input
              id="email"
              type="email"
              required
              autoComplete="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </Field>
          <Field label={t('auth.login.password')} htmlFor="password">
            <Input
              id="password"
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </Field>

          {error && (
            <div className="rounded-xl bg-error-container/30 border border-error/30 px-3 py-2 text-metadata text-error">
              {error}
            </div>
          )}

          <Button type="submit" loading={loading} fullWidth size="lg">
            {loading ? t('auth.login.submitting') : t('auth.login.submit')}
          </Button>
        </form>

        <p className="mt-8 text-center text-metadata text-on-surface-variant">
          {t('auth.login.noAccount')}{' '}
          <Link href="/register" className="font-semibold text-primary hover:underline">
            {t('auth.login.registerLink')}
          </Link>
        </p>
      </div>
    </main>
  );
}
