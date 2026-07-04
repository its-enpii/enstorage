import type { Metadata } from 'next';
import { pageTitle } from '@/lib/serverMetadata';
import ShareClient from '../ShareClient';

type Params = { token: string };

export async function generateMetadata(
  { params }: { params: Promise<Params> },
): Promise<Metadata> {
  await params;
  // Token doesn't reveal share contents server-side. Use a generic loading
  // label and let the client usePageTitle refine to "<folder/file name> · EnStorage"
  // once the listing fetch lands.
  return pageTitle('common.loadingLabel');
}

export default function Page() {
  return <ShareClient />;
}
