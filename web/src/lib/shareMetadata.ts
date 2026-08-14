import type { Metadata } from 'next';
import { bytes } from './format';

const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8080/api/v1';
const APP_URL = process.env.NEXT_PUBLIC_APP_URL || 'https://enstorage.enpiistudio.com';

type SharedInfo =
  | {
      kind: 'file';
      id: string;
      name: string;
      original_name: string;
      mime_type: string;
      size: number;
      updated_at: string | null;
    }
  | {
      kind: 'folder';
      folder: {
        id: string;
        name: string;
        path: string;
      };
      subfolders: Array<{ id: string; name: string }>;
      files: Array<{ id: string; name: string; size: number; mime_type: string }>;
    };

async function fetchSharedInfo(token: string): Promise<SharedInfo | null> {
  try {
    const res = await fetch(`${API_BASE}/s/${token}?info=1`, {
      headers: { Accept: 'application/json' },
      next: { revalidate: 60 },
    });
    if (!res.ok) return null;
    const json = await res.json();
    return json?.success && json?.data ? (json.data as SharedInfo) : null;
  } catch {
    return null;
  }
}

export async function generateSharedMetadata(
  token: string,
  mode: 'landing' | 'viewer' = 'landing',
): Promise<Metadata> {
  const info = await fetchSharedInfo(token);
  const defaultOgImage = `${APP_URL}/og-image.png`;

  if (!info) {
    return {
      title: 'Shared File · EnStorage',
      description: 'Bagikan dan unduh file dengan aman menggunakan EnStorage.',
      openGraph: {
        title: 'Shared File · EnStorage',
        description: 'Bagikan dan unduh file dengan aman menggunakan EnStorage.',
        type: 'website',
        siteName: 'EnStorage',
        images: [{ url: defaultOgImage, alt: 'EnStorage' }],
      },
      twitter: {
        card: 'summary',
        title: 'Shared File · EnStorage',
        description: 'Bagikan dan unduh file dengan aman menggunakan EnStorage.',
        images: [defaultOgImage],
      },
      robots: { index: false, follow: false },
    };
  }

  if (info.kind === 'folder') {
    const name = info.folder.name || 'Shared Folder';
    const count = (info.files?.length ?? 0) + (info.subfolders?.length ?? 0);
    const title = `${name} · EnStorage`;
    const description = `Folder bersama "${name}" berisi ${count} item di EnStorage.`;

    return {
      title,
      description,
      openGraph: {
        title,
        description,
        type: 'website',
        siteName: 'EnStorage',
        images: [{ url: defaultOgImage, alt: name }],
      },
      twitter: {
        card: 'summary',
        title,
        description,
        images: [defaultOgImage],
      },
    };
  }

  // File metadata
  const name = info.original_name || info.name || 'Shared File';
  const formattedSize = bytes(info.size);
  const mime = (info.mime_type || '').toLowerCase();
  const isImage = mime.startsWith('image/');
  const isVideo = mime.startsWith('video/');
  const isAudio = mime.startsWith('audio/');

  const titlePrefix = mode === 'viewer' ? `Preview: ${name}` : name;
  const title = `${titlePrefix} · EnStorage`;
  const description = `Unduh dan lihat file "${name}" (${formattedSize}) dengan aman di EnStorage.`;

  const ogImages = isImage
    ? [
        {
          url: `${API_BASE}/s/${token}`,
          alt: name,
        },
      ]
    : [
        {
          url: defaultOgImage,
          alt: 'EnStorage',
        },
      ];

  return {
    title,
    description,
    openGraph: {
      title,
      description,
      type: isVideo ? 'video.other' : isAudio ? 'music.song' : 'website',
      siteName: 'EnStorage',
      images: ogImages,
    },
    twitter: {
      card: isImage ? 'summary_large_image' : 'summary',
      title,
      description,
      images: isImage ? [`${API_BASE}/s/${token}`] : [defaultOgImage],
    },
  };
}
