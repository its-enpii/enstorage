import type { Metadata } from 'next';
import { pageTitle } from '@/lib/serverMetadata';
import LandingClient from './LandingClient';

// Description stays literal (like the root layout's) because metadata is
// rendered once on the server and cannot follow the client locale switch.
export const metadata: Metadata = {
  ...pageTitle('landing.pageTitle'),
  description:
    'Satu antarmuka untuk semua akun Google Drive Anda: agregasi multi-akun, smart routing kuota, ' +
    'REST API terbuka, dan privasi self-hosted 100%. Never Leaves Your Drive.',
};

export default function Page() {
  return <LandingClient />;
}
