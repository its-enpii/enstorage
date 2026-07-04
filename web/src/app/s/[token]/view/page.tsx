import type { Metadata } from 'next';
import { pageTitle } from '@/lib/serverMetadata';
import ShareClient from '../ShareClient';

type Params = { token: string };

export async function generateMetadata(
  { params }: { params: Promise<Params> },
): Promise<Metadata> {
  await params;
  // Viewer page should never leak file/folder name into <title> since the
  // whole point is chrome-less preview. Generic label only.
  return pageTitle('common.loadingLabel');
}

export default function Page() {
  return <ShareClient mode="viewer" />;
}
