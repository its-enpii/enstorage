'use client';

import { useAuth } from '@/components/AuthProvider';
import { Loading } from '@/components/Loading';
import LandingClient from './LandingClient';
import { DashboardClient } from './DashboardClient';

/**
 * Root route switch — authenticated users get the in-app Dashboard inside
 * `AppShell` (rendered by `DashboardClient`), guests keep the public landing.
 */
export default function HomeClient() {
  const { user, loading } = useAuth();

  if (loading) {
    return <Loading fullscreen />;
  }

  return user ? <DashboardClient /> : <LandingClient />;
}
