import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { generateSharedMetadata } from '@/lib/shareMetadata';
import ShareClient from './ShareClient';

const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8080/api/v1';

type PageProps = {
  params: Promise<{ token: string }>;
  searchParams: Promise<{ [key: string]: string | string[] | undefined }>;
};

export async function generateMetadata(
  { params }: { params: Promise<{ token: string }> },
): Promise<Metadata> {
  const { token } = await params;
  return generateSharedMetadata(token, 'landing');
}

export default async function Page({ params, searchParams }: PageProps) {
  const { token } = await params;
  const search = await searchParams;

  // Auto-download redirect when ?download=1 or ?download=true is in query
  if (search.download === '1' || search.download === 'true') {
    redirect(`${API_BASE}/s/${token}?download=1`);
  }

  // `?view=1` renders the immersive human viewer (fullscreen media + back /
  // download controls). The raw-bytes path stays at `/s/:token/view`, which
  // Next.js rewrites straight to the backend stream for bots / link previews.
  const mode = search.view === '1' ? 'viewer' : 'landing';

  return <ShareClient mode={mode} />;
}
