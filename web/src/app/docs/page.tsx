import type { Metadata } from 'next';
import { pageTitle } from '@/lib/serverMetadata';
import DocsClient from './DocsClient';

export const metadata: Metadata = {
  ...pageTitle('docs.pageTitle'),
  description:
    'Dokumentasi REST API EnStorage: autentikasi API key, scope, tabel endpoint, contoh cURL, ' +
    'response envelope, dan rate limit.',
};

export default function DocsPage() {
  return <DocsClient />;
}
