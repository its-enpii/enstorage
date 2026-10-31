'use client';

import { useEffect, useState, type FormEvent } from 'react';
import { useRouter } from 'next/navigation';
import Link from 'next/link';
import { useTranslation } from 'react-i18next';
import { Google, LockOutlined, MailOutlined } from '@mui/icons-material';
import { useAuth } from '@/components/AuthProvider';
import { Alert } from '@/components/Alert';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { Field, Input } from '@/components/Input';
import { apiRequest, ApiError } from '@/lib/api';
import { usePageTitle } from '@/lib/usePageTitle';

export default function LoginClient() {
  const { t } = useTranslation();
  const router = useRouter();
  const { user, loading, login, googleLogin } = useAuth();
  usePageTitle(t('auth.login.title'));

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [googleLoading, setGoogleLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Already signed in → straight to the vault.
  useEffect(() => {
    if (!loading && user) router.replace('/');
  }, [loading, user, router]);

  async function handleSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (submitting) return;
    setError(null);
    setSubmitting(true);
    try {
      await login(email.trim(), password);
      router.replace('/');
    } catch (err) {
      if (err instanceof ApiError) {
        setError(
          err.status === 401
            ? t('auth.login.invalidCredentials')
            : err.message || t('auth.login.failed'),
        );
      } else {
        setError(t('auth.login.failed'));
      }
      setSubmitting(false);
    }
  }

  async function handleGoogle() {
    if (googleLoading) return;
    setError(null);
    setGoogleLoading(true);
    try {
      await googleLogin();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : t('auth.login.failed'));
      setGoogleLoading(false);
    }
  }

  return (
    <main className="min-h-screen flex items-center justify-center bg-background px-4 py-10">
      <Card className="w-full max-w-sm sm:max-w-md">
        <div className="mb-6">
          <h1 className="font-display text-headline-lg text-on-surface">{t('auth.login.title')}</h1>
          <p className="text-metadata text-outline mt-1">{t('auth.login.subtitle')}</p>
        </div>

        <form onSubmit={handleSubmit} className="flex flex-col gap-4" noValidate>
          <Field label={t('auth.login.email')} htmlFor="login-email">
            <Input
              id="login-email"
              type="email"
              autoComplete="email"
              inputMode="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder={t('auth.login.emailPlaceholder')}
              leftIcon={<MailOutlined className="!text-lg" />}
              invalid={!!error && !email.trim()}
            />
          </Field>
          <Field label={t('auth.login.password')} htmlFor="login-password">
            <Input
              id="login-password"
              type="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder={t('auth.login.passwordPlaceholder')}
              leftIcon={<LockOutlined className="!text-lg" />}
              invalid={!!error && !password}
            />
          </Field>

          {error && <Alert className="!text-metadata">{error}</Alert>}

          <Button type="submit" size="lg" fullWidth loading={submitting} disabled={!email.trim() || !password}>
            {submitting ? t('auth.login.submitting') : t('auth.login.submit')}
          </Button>
        </form>

        <div className="flex items-center gap-3 my-5" role="separator" aria-label={t('auth.login.orDivider')}>
          <span className="h-px flex-1 bg-outline-variant/30" />
          <span className="text-metadata text-outline uppercase tracking-wider">{t('auth.login.orDivider')}</span>
          <span className="h-px flex-1 bg-outline-variant/30" />
        </div>

        <Button
          type="button"
          variant="secondary"
          size="lg"
          fullWidth
          leftIcon={<Google className="!text-lg" />}
          onClick={handleGoogle}
          loading={googleLoading}
          disabled={googleLoading}
        >
          {googleLoading ? t('auth.login.googleLoading') : t('auth.login.googleButton')}
        </Button>

        <p className="mt-6 text-center text-metadata text-outline">
          {t('auth.login.noAccount')}{' '}
          <Link href="/" className="text-primary font-semibold hover:underline underline-offset-2">
            {t('auth.login.registerLink')}
          </Link>
        </p>
      </Card>
    </main>
  );
}
