'use client';

import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Cloud, Logout, Person, Star, Storage, Edit, Save, Cancel } from '@mui/icons-material';
import { apiRequest, ApiError } from '@/lib/api';
import { AppShell } from '@/components/AppShell';
import { Button, IconButton } from '@/components/Button';
import { Field, Input } from '@/components/Input';
import { Chip } from '@/components/Chip';
import { usePrompt } from '@/components/usePrompt';
import { useAuth } from '@/components/AuthProvider';
import { useRouter } from 'next/navigation';
import { usePageTitle } from '@/lib/usePageTitle';

export default function ProfilePage() {
  return (
    <AppShell>
      <ProfileContent />
    </AppShell>
  );
}

function ProfileContent() {
  const { t } = useTranslation();
  const { user, logout, refresh } = useAuth();
  const router = useRouter();
  const { alert, confirm } = usePrompt();
  usePageTitle(t('profile.title'));
  const [editing, setEditing] = useState(false);
  const [saving, setSaving] = useState(false);
  const [name, setName] = useState(user?.name ?? '');
  const [email, setEmail] = useState(user?.email ?? '');
  const [error, setError] = useState<string | null>(null);

  // Password
  const [pwForm, setPwForm] = useState({ current: '', next: '', confirm: '' });
  const [pwSaving, setPwSaving] = useState(false);
  const [pwError, setPwError] = useState<string | null>(null);

  useEffect(() => {
    if (user) {
      setName(user.name);
      setEmail(user.email);
    }
  }, [user]);

  const dirty = user ? name !== user.name || email !== user.email : false;

  async function saveProfile() {
    if (!dirty || saving) return;
    setSaving(true);
    setError(null);
    try {
      await apiRequest<unknown>('/auth/me', {
        method: 'PATCH',
        body: { name: name.trim(), email: email.trim() },
      });
      await refresh();
      setEditing(false);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : t('profile.saveFailed'));
    } finally {
      setSaving(false);
    }
  }

  function cancelEdit() {
    if (!user) return;
    setName(user.name);
    setEmail(user.email);
    setError(null);
    setEditing(false);
  }

  async function changePassword() {
    setPwError(null);
    if (!pwForm.current || !pwForm.next) {
      setPwError(t('profile.pwFillAll'));
      return;
    }
    if (pwForm.next !== pwForm.confirm) {
      setPwError(t('profile.pwMismatch'));
      return;
    }
    if (pwForm.next.length < 8) {
      setPwError(t('profile.pwMinLen'));
      return;
    }
    setPwSaving(true);
    try {
      await apiRequest<null>('/auth/change-password', {
        method: 'POST',
        body: {
          current_password: pwForm.current,
          new_password: pwForm.next,
          new_password_confirmation: pwForm.confirm,
        },
      });
      setPwForm({ current: '', next: '', confirm: '' });
      await alert(t('profile.pwSuccess'), { title: t('profile.success') });
    } catch (e) {
      setPwError(e instanceof ApiError ? e.message : t('profile.pwFailed'));
    } finally {
      setPwSaving(false);
    }
  }

  async function handleLogout() {
    const ok = await confirm(t('profile.logoutConfirmDesc'), {
      title: t('profile.logoutConfirmTitle'),
      danger: true,
      confirmLabel: t('profile.logout'),
    });
    if (!ok) return;
    await logout();
    router.replace('/login');
  }

  if (!user) return null;

  return (
    <>
      <nav className="flex items-center gap-2 mb-6 mt-2 text-metadata text-outline">
        <span>{t('nav.home')}</span>
        <span>/</span>
        <span className="text-on-surface">{t('nav.profile')}</span>
      </nav>

      {/* Hero */}
      <div className="bg-surface p-inner-padding rounded-card shadow-inner-glow flex items-center gap-6 mb-card-gap">
        <div className="w-20 h-20 rounded-2xl bg-primary-container flex items-center justify-center text-on-primary-container font-display text-3xl font-semibold shrink-0">
          {user.name?.[0]?.toUpperCase() ?? <Person className="!text-5xl" />}
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 flex-wrap">
            <h1 className="font-display text-headline-lg text-on-surface truncate">
              {user.name}
            </h1>
            {user.role === 'owner' && <Chip variant="warning">{t('profile.owner')}</Chip>}
            {user.email_verified_at && <Chip variant="success">{t('profile.verified')}</Chip>}
            {!user.is_active && <Chip variant="danger">{t('profile.inactive')}</Chip>}
          </div>
          <p className="text-metadata text-outline mt-1 truncate">{user.email}</p>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-card-gap">
        {/* Identitas + edit */}
        <section className="bg-surface p-inner-padding rounded-card shadow-inner-glow flex flex-col gap-5">
          <div className="flex items-center justify-between">
            <h2 className="font-body text-body-lg font-semibold text-on-surface">{t('profile.identity')}</h2>
            {!editing && (
              <IconButton
                onClick={() => setEditing(true)}
                title={t('profile.editProfile')}
                active={false}
              >
                <Edit className="!text-base" />
              </IconButton>
            )}
          </div>

          {editing ? (
            <>
              <Field label={t('profile.name')}>
                <Input
                  autoFocus
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  maxLength={255}
                />
              </Field>
              <Field label={t('profile.email')}>
                <Input
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  maxLength={255}
                />
              </Field>
              {error && (
                <div className="rounded-xl bg-error-container/30 border border-error/30 px-3 py-2 text-metadata text-error">
                  {error}
                </div>
              )}
              <div className="flex justify-end gap-2">
                <Button variant="secondary" onClick={cancelEdit} leftIcon={<Cancel />}>
                  {t('profile.cancel')}
                </Button>
                <Button
                  onClick={saveProfile}
                  loading={saving}
                  disabled={!dirty || !name.trim() || !email.trim()}
                  leftIcon={<Save />}
                >
                  {t('profile.save')}
                </Button>
              </div>
            </>
          ) : (
            <>
              <Row label={t('profile.name')} value={user.name} />
              <Row label={t('profile.email')} value={user.email} />
              <Row label={t('profile.role')} value={user.role === 'owner' ? t('profile.owner') : t('profile.member')} />
              <Row
                label={t('profile.registeredSince')}
                value={new Date(user.created_at).toLocaleDateString(undefined, {
                  dateStyle: 'long',
                })}
              />
            </>
          )}
        </section>

        {/* Keamanan */}
        <section className="bg-surface p-inner-padding rounded-card shadow-inner-glow flex flex-col gap-4">
          <h2 className="font-body text-body-lg font-semibold text-on-surface">{t('profile.security')}</h2>
          <Field label={t('profile.currentPassword')}>
            <Input
              type="password"
              value={pwForm.current}
              onChange={(e) => setPwForm((f) => ({ ...f, current: e.target.value }))}
              autoComplete="current-password"
            />
          </Field>
          <Field label={t('profile.newPassword')} hint={t('profile.pwMinHint')}>
            <Input
              type="password"
              value={pwForm.next}
              onChange={(e) => setPwForm((f) => ({ ...f, next: e.target.value }))}
              autoComplete="new-password"
            />
          </Field>
          <Field label={t('profile.confirmPassword')}>
            <Input
              type="password"
              value={pwForm.confirm}
              onChange={(e) => setPwForm((f) => ({ ...f, confirm: e.target.value }))}
              autoComplete="new-password"
            />
          </Field>
          {pwError && (
            <div className="rounded-xl bg-error-container/30 border border-error/30 px-3 py-2 text-metadata text-error">
              {pwError}
            </div>
          )}
          <div className="flex justify-end">
            <Button
              onClick={changePassword}
              loading={pwSaving}
              disabled={!pwForm.current || !pwForm.next || !pwForm.confirm}
            >
              {t('profile.changePassword')}
            </Button>
          </div>
        </section>

        {/* Statistik */}
        {user.counts && (
          <section className="lg:col-span-2 bg-surface p-inner-padding rounded-card shadow-inner-glow">
            <h2 className="font-body text-body-lg font-semibold text-on-surface mb-6">
              {t('profile.vaultStats')}
            </h2>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
              <Stat label={t('profile.googleAccounts')} value={user.counts.google_accounts} icon={<Cloud />} />
              <Stat label={t('profile.folders')} value={user.counts.folders} icon={<Storage />} />
              <Stat label={t('profile.files')} value={user.counts.files} icon={<Star />} />
              <Stat label={t('profile.apiKeys')} value={user.counts.api_keys} icon={<Star />} />
            </div>
          </section>
        )}

        {/* Zona Berbahaya */}
        <section className="lg:col-span-2 bg-surface p-inner-padding rounded-card shadow-inner-glow border border-error/20">
          <h2 className="font-body text-body-lg font-semibold text-error mb-2">
            {t('profile.dangerZone')}
          </h2>
          <p className="text-metadata text-outline mb-6">
            {t('profile.dangerDesc')}
          </p>
          <Button variant="danger-soft" onClick={handleLogout} leftIcon={<Logout />}>
            {t('profile.logout')}
          </Button>
        </section>
      </div>
    </>
  );
}

function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between gap-4 py-1.5 border-b border-outline-variant/10 last:border-0">
      <span className="text-metadata text-outline">{label}</span>
      <span className="text-sm text-on-surface font-medium text-right break-all">{value}</span>
    </div>
  );
}

function Stat({
  label,
  value,
  icon,
}: {
  label: string;
  value: number;
  icon: React.ReactNode;
}) {
  return (
    <div className="bg-surface-container rounded-xl p-4 flex items-center gap-3">
      <div className="w-10 h-10 rounded-lg bg-primary-container flex items-center justify-center text-on-primary-container shrink-0">
        {icon}
      </div>
      <div className="min-w-0">
        <p className="text-metadata text-outline uppercase tracking-wider">{label}</p>
        <p className="text-2xl font-semibold text-on-surface font-display">{value}</p>
      </div>
    </div>
  );
}
