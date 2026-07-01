import type { Metadata } from 'next';
import { pageTitle } from '@/lib/serverMetadata';
import FilesClient from './FilesClient';

type Params = { path?: string[] };

export async function generateMetadata(
  { params }: { params: Promise<Params> },
): Promise<Metadata> {
  // Folder name is unavailable server-side (auth token is localStorage-only,
  // no cookie). Client usePageTitle refines to "<folder> · File Saya · EnStorage"
  // after mount once the breadcrumb fetch lands.
  await params;
  return pageTitle('files.title');
}

export default function Page() {
  return <FilesClient />;
}
