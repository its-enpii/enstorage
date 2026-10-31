import type { Metadata } from 'next';
import { pageTitle } from '@/lib/serverMetadata';
import CategoryClient from './CategoryClient';

type Params = { type: string };

const META_KEY_MAP: Record<string, string> = {
  doc: 'categories.document',
  image: 'categories.image',
  video: 'categories.video',
  audio: 'categories.audio',
  app: 'categories.app',
  archive: 'categories.archive',
};

export async function generateMetadata(
  { params }: { params: Promise<Params> },
): Promise<Metadata> {
  const { type } = await params;
  const key = META_KEY_MAP[type] ?? 'dashboard.categoriesTitle';
  return pageTitle(key);
}

export default async function Page({
  params,
}: {
  params: Promise<Params>;
}) {
  const { type } = await params;
  return <CategoryClient type={type} />;
}
