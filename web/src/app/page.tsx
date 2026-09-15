import type { Metadata } from 'next';
import { pageTitle } from '@/lib/serverMetadata';
import LandingClient from './LandingClient';

// Description stays literal (like the root layout's) because metadata is
// rendered once on the server and cannot follow the client locale switch.
export const metadata: Metadata = {
  ...pageTitle('landing.pageTitle'),
  description:
    'Satu tempat untuk semua akun Google Drive Anda: kuota digabung, file otomatis disimpan ke akun ' +
    'dengan ruang kosong terbesar, dapat dibuka lewat web, dan tersedia lewat REST API.',
};

export default function Page() {
  return <LandingClient />;
}
