'use client';

import { useCallback, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { apiRequest, ApiError, getToken, type FileItem, type Folder as FolderType } from '@/lib/api';
import { AppShell } from '@/components/AppShell';
import { IconButton } from '@/components/Button';
import { DropdownMenu, type MenuItem } from '@/components/DropdownMenu';
import { FileViewer } from '@/components/FileViewer';
import { ShareDialog } from '@/components/ShareDialog';
import { ItemCard } from '@/components/ItemCard';
import { Loading } from '@/components/Loading';
import { Tabs } from '@/components/Tabs';
import { usePrompt } from '@/components/usePrompt';
import { bytes } from '@/lib/format';
import { createViewStore } from '@/lib/viewStore';
import {
  CloudDoneIcon,
  FileIcon,
  FolderSpecialIcon,
  StarIcon,
  StarBorderIcon,
  IconSymbol,
} from '@/lib/icons';

type Tab = 'all' | 'folders' | 'files';
type StarredData = { folders: FolderType[]; files: FileItem[] };

const starredStore = createViewStore<StarredData>(async () => {
  const [folders, files] = await Promise.all([
    apiRequest<FolderType[]>('/folders?starred=true'),
    apiRequest<FileItem[]>('/files?starred=true'),
  ]);
  return { folders, files };
});

function fileIcon(file: FileItem) {
  if (file.has_thumbnail && file.upload_status === 'done') {
    const token = getToken();
    const src = `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/thumbnail${token ? `?token=${encodeURIComponent(token)}` : ''}`;
    return (
      <img
        src={src}
        alt=""
        className="w-16 h-16 object-cover rounded-xl"
        loading="lazy"
      />
    );
  }
  return <FileIcon mime={file.mime_type} className="text-secondary" />;
}

function statusLabel(t: (key: string) => string, s: FileItem['upload_status']) {
  if (s === 'done') return t('starred.statusDone');
  if (s === 'failed') return t('starred.statusFailed');
  if (s === 'uploading') return t('starred.statusUploading');
  return t('starred.statusPending');
}

export default function StarredPage() {
  return (
    <AppShell>
      <starredStore.Provider viewKey="starred">
        <StarredContent />
      </starredStore.Provider>
    </AppShell>
  );
}

function StarredContent() {
  const { t } = useTranslation();
  const router = useRouter();
  const { alert, confirm, prompt } = usePrompt();
  const [tab, setTab] = useState<Tab>('all');
  const [viewerFile, setViewerFile] = useState<FileItem | null>(null);
  const [shareFile, setShareFile] = useState<FileItem | null>(null);
  const { data, loading, error, setData, revalidate } = starredStore.useStore();
  const folders = data?.folders ?? [];
  const files = data?.files ?? [];

  async function toggleStarFolder(id: string) {
    setData((prev) => prev ? { ...prev, folders: prev.folders.filter((f) => f.id !== id) } : prev);
    try {
      await apiRequest<FolderType>(`/folders/${id}`, {
        method: 'PATCH',
        body: { is_starred: false },
      });
    } catch (e) {
      void revalidate();
      await alert(e instanceof ApiError ? e.message : t('files.errors.starFailed'));
    }
  }

  async function toggleStarFile(id: string) {
    setData((prev) => prev ? { ...prev, files: prev.files.filter((f) => f.id !== id) } : prev);
    try {
      await apiRequest<FileItem>(`/files/${id}`, {
        method: 'PATCH',
        body: { is_starred: false },
      });
    } catch (e) {
      void revalidate();
      await alert(e instanceof ApiError ? e.message : t('files.errors.starFailed'));
    }
  }

  async function renameFile(file: FileItem) {
    const name = await prompt(t('files.renameDesc'), { title: t('files.renameTitle'), defaultValue: file.name });
    if (!name?.trim() || name.trim() === file.name) return;
    try {
      await apiRequest<FileItem>(`/files/${file.id}`, {
        method: 'PATCH',
        body: { name: name.trim() },
      });
      setData((prev) => prev ? { ...prev, files: prev.files.map((f) => f.id === file.id ? { ...f, name: name.trim() } : f) } : prev);
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('files.errors.renameFailed'));
    }
  }

  async function copyFileToClipboard(file: FileItem) {
    try {
      const dlToken = getToken();
      const url = `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/download?inline=1${dlToken ? `&token=${encodeURIComponent(dlToken)}` : ''}`;
      const res = await fetch(url);
      const blob = await res.blob();
      await navigator.clipboard.write([new ClipboardItem({ [blob.type]: blob })]);
      await alert(t('files.copySuccess'));
    } catch (e) {
      await alert(e instanceof Error ? e.message : t('files.errors.copyFailed'));
    }
  }

  async function deleteFileFromStarred(id: string) {
    const ok = await confirm(t('files.confirmDelete.body'), {
      title: t('files.confirmDelete.title'),
      danger: true,
      confirmLabel: t('files.confirmDelete.confirm'),
    });
    if (!ok) return;
    setData((prev) => prev ? { ...prev, files: prev.files.filter((f) => f.id !== id) } : prev);
    try {
      await apiRequest<null>(`/files/${id}`, { method: 'DELETE' });
    } catch (e) {
      void revalidate();
      await alert(e instanceof ApiError ? e.message : t('files.errors.deleteFailed'));
    }
  }

  function buildFileMenuItems(f: FileItem): MenuItem[] {
    return [
      {
        label: t('files.actions.unstar'),
        icon: <span className="material-symbols-outlined !text-base fill">star</span>,
        onClick: () => toggleStarFile(f.id),
      },
      {
        label: t('files.actions.preview'),
        icon: <span className="material-symbols-outlined !text-base">visibility</span>,
        onClick: () => setViewerFile(f),
      },
      {
        label: t('files.actions.rename'),
        icon: <span className="material-symbols-outlined !text-base">edit</span>,
        onClick: () => renameFile(f),
      },
      {
        label: t('files.actions.download'),
        icon: <span className="material-symbols-outlined !text-base">download</span>,
        onClick: () => downloadFile(f.id),
      },
      {
        label: t('files.actions.share'),
        icon: <span className="material-symbols-outlined !text-base">link</span>,
        onClick: () => setShareFile(f),
      },
      {
        label: t('files.actions.copy'),
        icon: <span className="material-symbols-outlined !text-base">content_copy</span>,
        onClick: () => copyFileToClipboard(f),
      },
      {
        label: t('files.actions.delete'),
        icon: <span className="material-symbols-outlined !text-base">delete</span>,
        onClick: () => deleteFileFromStarred(f.id),
        variant: 'danger' as const,
      },
    ];
  }

  async function downloadFile(id: string) {
    const token = getToken();
    const url = `${process.env.NEXT_PUBLIC_API_BASE}/files/${id}/download`;
    try {
      const res = await fetch(url, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
      });
      if (!res.ok) throw new Error(t('files.errors.downloadFailed'));
      const blob = await res.blob();
      const dl = document.createElement('a');
      dl.href = URL.createObjectURL(blob);
      dl.download = '';
      document.body.appendChild(dl);
      dl.click();
      dl.remove();
    } catch (e) {
      await alert(e instanceof Error ? e.message : t('files.errors.downloadFailed'));
    }
  }

  const visibleFolders = tab === 'files' ? [] : folders;
  const visibleFiles = tab === 'folders' ? [] : files;
  const empty = !loading && folders.length === 0 && files.length === 0;

  return (
    <>
      <div className="flex items-end justify-between mb-8">
        <h1 className="font-display text-3xl font-semibold text-on-surface flex items-center gap-2">
          <span className={`${IconSymbol} !text-3xl fill text-secondary`}>star</span>
          {t('starred.title')}
        </h1>
        <Tabs
          tabs={[
            { value: 'all', label: t('files.tabs.all') },
            { value: 'folders', label: t('files.tabs.folders') },
            { value: 'files', label: t('files.tabs.files') },
          ]}
          value={tab}
          onChange={(v) => setTab(v as Tab)}
        />
      </div>

      {error && (
        <div className="mb-6 rounded-xl bg-error-container/30 border border-error/30 px-4 py-2 text-sm text-error">
          {error}
        </div>
      )}

      {loading ? (
        <Loading label={t('common.loadingLabel')} />
      ) : empty ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-card-gap">
          <div className="col-span-full border-2 border-dashed border-outline-variant/20 rounded-card p-inner-padding flex flex-col items-center justify-center gap-4">
            <div className="w-16 h-16 rounded-2xl bg-surface-container flex items-center justify-center text-outline">
              <span className={`${IconSymbol} !text-4xl fill`}>star_border</span>
            </div>
            <p className="text-sm text-on-surface text-center">{t('starred.empty')}</p>
            <p className="text-metadata text-outline text-center">
              {t('starred.emptyDesc')}
            </p>
          </div>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-card-gap">
          {visibleFolders.map((f) => {
            const items = (f.files_count ?? 0) + (f.folders_count ?? 0);
            const size = f.total_size ?? 0;
            return (
              <ItemCard
                key={f.id}
                icon={<FolderSpecialIcon />}
              iconVariant="gold"
                title={f.name}
                subtitle={
                  items > 0
                    ? (size > 0 ? t('folders.itemsSize', { count: items, size: bytes(size) }) : t('folders.items', { count: items }))
                    : t('folders.items', { count: 0 })
                }
                onClick={() => router.push(`/files/${f.id}`)}
                right={
                  <div className="opacity-0 group-hover:opacity-100 flex items-center gap-1">
                    <IconButton
                      onClick={(e) => {
                        e.stopPropagation();
                        toggleStarFolder(f.id);
                      }}
                      title={t('starred.unstar')}
                      active
                    >
                      <StarIcon />
                    </IconButton>
                  </div>
                }
              />
            );
          })}
          {visibleFiles.map((f) => (
            <ItemCard
              key={f.id}
              icon={fileIcon(f)}
              title={f.name}
              subtitle={`${bytes(f.size)} • ${statusLabel(t, f.upload_status)}`}
              onClick={() => f.upload_status === 'done' && setViewerFile(f)}
              right={
                <DropdownMenu
                  align="right"
                  trigger={
                    <IconButton title={t('files.actions.menu')}>
                      <span className="material-symbols-outlined !text-base">more_vert</span>
                    </IconButton>
                  }
                  items={buildFileMenuItems(f)}
                />
              }
            />
          ))}
        </div>
      )}

      {viewerFile && (
        <FileViewer
          file={viewerFile}
          files={visibleFiles.filter((f) => f.upload_status === 'done')}
          onClose={() => setViewerFile(null)}
          onNavigate={setViewerFile}
          actions={buildFileMenuItems(viewerFile)}
        />
      )}
      {shareFile && (
        <ShareDialog
          file={shareFile}
          onClose={() => setShareFile(null)}
          onUpdate={(updated) => {
            setData((prev) => prev ? { ...prev, files: prev.files.map((f) => f.id === updated.id ? updated : f) } : prev);
            setShareFile(updated);
          }}
        />
      )}
    </>
  );
}
