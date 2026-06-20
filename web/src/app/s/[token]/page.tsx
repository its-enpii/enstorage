'use client';

import { useParams } from 'next/navigation';
import { useTranslation } from 'react-i18next';

const API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? 'http://localhost:8080/api/v1';

export default function SharePage() {
  const { t } = useTranslation();
  const params = useParams();
  const token = params.token as string;

  const viewUrl = `${API_BASE}/s/${token}`;
  const downloadUrl = `${API_BASE}/s/${token}?download=1`;

  return (
    <div className="min-h-screen flex items-center justify-center bg-background p-4">
      <div className="w-full max-w-sm bg-surface rounded-card shadow-ambient p-8 text-center">
        <div className="w-16 h-16 rounded-2xl bg-primary-container flex items-center justify-center mx-auto mb-4">
          <span className="material-symbols-outlined !text-4xl fill text-on-primary-container">description</span>
        </div>
        <h1 className="font-display text-lg font-semibold text-on-surface mb-4">{t('share.sharedFile')}</h1>
        <p className="text-metadata text-outline mb-6">{t('share.sharedDesc')}</p>
        <a
          href={downloadUrl}
          className="inline-flex items-center gap-2 px-6 py-3 bg-primary text-on-primary rounded-full hover:bg-primary/90 transition-colors font-medium"
        >
          <span className="material-symbols-outlined !text-xl">download</span>
          {t('files.actions.download')}
        </a>
        <p className="mt-6 text-xs text-outline">{t('share.sharedVia')}</p>
      </div>
    </div>
  );
}
