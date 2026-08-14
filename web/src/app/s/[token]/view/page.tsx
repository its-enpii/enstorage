import type { Metadata } from 'next';
import { generateSharedMetadata } from '@/lib/shareMetadata';
import ShareClient from '../ShareClient';

type Params = { token: string };

export async function generateMetadata(
  { params }: { params: Promise<Params> },
): Promise<Metadata> {
  const { token } = await params;
  return generateSharedMetadata(token, 'viewer');
}

export default function Page() {
  return <ShareClient mode="viewer" />;
}
