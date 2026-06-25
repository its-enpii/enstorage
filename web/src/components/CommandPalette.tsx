'use client';

import { useEffect, useRef, useState, useCallback } from 'react';
import { useRouter } from 'next/navigation';
import { useTranslation } from 'react-i18next';
import { Search as SearchIcon, Close, SentimentDissatisfied } from '@mui/icons-material';
import clsx from 'clsx';
import { apiRequest, ApiError, getToken, type FileItem, type Folder as FolderType } from '@/lib/api';
import { FileIcon, FolderIcon } from '@/lib/icons';
import { bytes } from '@/lib/format';
import { Loading } from '@/components/Loading';
import { FileViewer } from '@/components/FileViewer';
import { ShareDialog } from '@/components/ShareDialog';
import { usePrompt } from '@/components/usePrompt';

type Result =
  | { kind: 'file'; data: FileItem }
  | { kind: 'folder'; data: FolderType };

type Props = {
  open: boolean;
  onClose: () => void;
};

export function CommandPalette({ open, onClose }: Props) {
  const { t } = useTranslation();
  const router = useRouter();
  const { alert, confirm, prompt } = usePrompt();
  // File selected for in-place preview (rendered on top of the palette)
  const [previewFile, setPreviewFile] = useState<FileItem | null>(null);
  // All files in the current results, used for prev/next navigation
  const [allFiles, setAllFiles] = useState<FileItem[]>([]);
  // Share dialog state (digunakan oleh aksi "Share Link" di preview)
  const [shareFile, setShareFile] = useState<FileItem | null>(null);
  const [query, setQuery] = useState('');
  const [debounced, setDebounced] = useState('');
  const [files, setFiles] = useState<FileItem[]>([]);
  const [folders, setFolders] = useState<FolderType[]>([]);
  const [loading, setLoading] = useState(false);
  const [activeIdx, setActiveIdx] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const abortRef = useRef<AbortController | null>(null);

  // Debounce query 500ms
  useEffect(() => {
    const t = setTimeout(() => setDebounced(query.trim()), 500);
    return () => clearTimeout(t);
  }, [query]);

  // Reset on open
  useEffect(() => {
    if (open) {
      setQuery('');
      setDebounced('');
      setFiles([]);
      setFolders([]);
      setAllFiles([]);
      setPreviewFile(null);
      setShareFile(null);
      setActiveIdx(0);
      // focus input on next tick so modal is mounted
      setTimeout(() => inputRef.current?.focus(), 0);
    }
  }, [open]);

  // Lock body scroll
  useEffect(() => {
    if (!open) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => { document.body.style.overflow = prev; };
  }, [open]);

  // Fetch results when debounced query changes
  useEffect(() => {
    if (!open) return;
    if (!debounced) {
      setFiles([]);
      setFolders([]);
      setAllFiles([]);
      return;
    }
    abortRef.current?.abort();
    const ctrl = new AbortController();
    abortRef.current = ctrl;
    setLoading(true);
    Promise.all([
      apiRequest<FileItem[]>(`/files?search=${encodeURIComponent(debounced)}&per_page=10`),
      apiRequest<FolderType[]>(`/folders?search=${encodeURIComponent(debounced)}`),
    ])
      .then(([f, fd]) => {
        if (ctrl.signal.aborted) return;
        setFiles(f);
        setFolders(fd);
        setAllFiles(f);
        setActiveIdx(0);
      })
      .catch(() => { /* ignore */ })
      .finally(() => { if (!ctrl.signal.aborted) setLoading(false); });
    return () => ctrl.abort();
  }, [debounced, open]);

  const results: Result[] = [
    ...folders.map((f) => ({ kind: 'folder' as const, data: f })),
    ...files.map((f) => ({ kind: 'file' as const, data: f })),
  ];

  const select = useCallback(
    (r: Result) => {
      if (r.kind === 'folder') {
        router.push(`/files/${r.data.id}`);
        onClose();
      } else {
        // File → preview in-place, no navigation. Tutup palette adalah explicit
        // (Esc/click outside) supaya user bisa browse beberapa file tanpa flicker.
        setPreviewFile(r.data);
      }
    },
    [router, onClose],
  );

  // === Aksi untuk FileViewer di dalam palette ===
  // (Palette tidak punya store global; mutasi langsung via API.)

  async function downloadOne(id: string) {
    const token = getToken();
    const url = `${process.env.NEXT_PUBLIC_API_BASE}/files/${id}/download`;
    try {
      const res = await fetch(url, {
        headers: token ? { Authorization: `Bearer ${token}` } : {},
      });
      if (!res.ok) throw new Error(t('files.errors.downloadFailed'));
      const blob = await res.blob();
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = '';
      document.body.appendChild(a);
      a.click();
      a.remove();
    } catch (e) {
      await alert(e instanceof Error ? e.message : t('files.errors.downloadFailed'));
    }
  }

  async function copyFileToClipboard(file: FileItem) {
    try {
      const token = getToken();
      const url = `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/download?inline=1${token ? `&token=${encodeURIComponent(token)}` : ''}`;
      const res = await fetch(url);
      const blob = await res.blob();
      await navigator.clipboard.write([
        new ClipboardItem({ [blob.type]: blob }),
      ]);
      await alert(t('files.copySuccess'));
    } catch (e) {
      await alert(e instanceof Error ? e.message : t('files.errors.copyFailed'));
    }
  }

  async function toggleStar(file: FileItem) {
    // Optimistik update pada state lokal preview & list
    const next = !file.is_starred;
    const updated: FileItem = { ...file, is_starred: next };
    setPreviewFile((p) => (p && p.id === file.id ? updated : p));
    setAllFiles((arr) => arr.map((f) => (f.id === file.id ? updated : f)));
    setFiles((arr) => arr.map((f) => (f.id === file.id ? updated : f)));
    try {
      await apiRequest<FileItem>(`/files/${file.id}`, {
        method: 'PATCH',
        body: { is_starred: next },
      });
    } catch (e) {
      // Rollback
      setPreviewFile((p) => (p && p.id === file.id ? file : p));
      setAllFiles((arr) => arr.map((f) => (f.id === file.id ? file : f)));
      setFiles((arr) => arr.map((f) => (f.id === file.id ? file : f)));
      await alert(e instanceof ApiError ? e.message : t('files.errors.starFailed'));
    }
  }

  async function renameFile(file: FileItem) {
    const name = await prompt(t('files.renameDesc'), { title: t('files.renameTitle'), defaultValue: file.name });
    if (!name?.trim() || name.trim() === file.name) return;
    try {
      const updated = await apiRequest<FileItem>(`/files/${file.id}`, {
        method: 'PATCH',
        body: { name: name.trim() },
      });
      setPreviewFile((p) => (p && p.id === file.id ? updated : p));
      setAllFiles((arr) => arr.map((f) => (f.id === file.id ? updated : f)));
      setFiles((arr) => arr.map((f) => (f.id === file.id ? updated : f)));
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('files.errors.renameFailed'));
    }
  }

  async function deleteFile(file: FileItem) {
    const ok = await confirm(t('files.confirmDelete.body'), {
      title: t('files.confirmDelete.title'),
      danger: true,
      confirmLabel: t('files.confirmDelete.confirm'),
    });
    if (!ok) return;
    // Tutup preview & bersihkan dari list
    setPreviewFile(null);
    setAllFiles((arr) => arr.filter((f) => f.id !== file.id));
    setFiles((arr) => arr.filter((f) => f.id !== file.id));
    try {
      await apiRequest<null>(`/files/${file.id}`, { method: 'DELETE' });
    } catch (e) {
      await alert(e instanceof ApiError ? e.message : t('files.errors.deleteFailed'));
    }
  }

  function buildPreviewActions(file: FileItem) {
    return [
      {
        label: file.is_starred ? t('files.actions.unstar') : t('files.actions.star'),
        icon: <span className="material-symbols-outlined !text-base fill">{file.is_starred ? 'star' : 'star_border'}</span>,
        onClick: () => toggleStar(file),
      },
      {
        label: t('files.actions.rename'),
        icon: <span className="material-symbols-outlined !text-base">edit</span>,
        onClick: () => renameFile(file),
      },
      {
        label: t('files.actions.download'),
        icon: <span className="material-symbols-outlined !text-base">download</span>,
        onClick: () => downloadOne(file.id),
      },
      {
        label: t('files.actions.share'),
        icon: <span className="material-symbols-outlined !text-base">link</span>,
        onClick: () => setShareFile(file),
      },
      {
        label: t('files.actions.copy'),
        icon: <span className="material-symbols-outlined !text-base">content_copy</span>,
        onClick: () => copyFileToClipboard(file),
      },
      {
        label: t('files.actions.delete'),
        icon: <span className="material-symbols-outlined !text-base">delete</span>,
        onClick: () => deleteFile(file),
        variant: 'danger' as const,
      },
    ];
  }

  // Keyboard nav (only when no preview is open; FileViewer handles its own keys)
  useEffect(() => {
    if (!open || previewFile) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onClose();
        return;
      }
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setActiveIdx((i) => Math.min(i + 1, results.length - 1));
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        setActiveIdx((i) => Math.max(i - 1, 0));
      } else if (e.key === 'Enter' && results[activeIdx]) {
        e.preventDefault();
        select(results[activeIdx]);
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [open, results, activeIdx, select, onClose]);

  if (!open) return null;

  // In-place preview overlay: render full-screen FileViewer on top of palette.
  // Closing FileViewer juga menutup palette (sesuai requirement: search hidden setelah preview).
  if (previewFile) {
    return (
      <>
        <FileViewer
          file={previewFile}
          files={allFiles}
          onClose={onClose}
          onNavigate={setPreviewFile}
          actions={buildPreviewActions(previewFile)}
        />
        {shareFile && (
          <ShareDialog
            target={{ kind: 'file', item: shareFile }}
            onClose={() => setShareFile(null)}
            onUpdate={(updated) => {
              if (updated.kind !== 'file') return;
              const f = updated.item;
              setShareFile(f);
              setPreviewFile((p) => (p && p.id === f.id ? f : p));
              setAllFiles((arr) => arr.map((x) => (x.id === f.id ? f : x)));
              setFiles((arr) => arr.map((x) => (x.id === f.id ? f : x)));
            }}
          />
        )}
      </>
    );
  }

  return (
    <div
      className="fixed inset-0 z-[80] flex items-start justify-center pt-[10vh] px-4 bg-background/75 backdrop-blur-sm"
      onClick={onClose}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="w-full max-w-2xl bg-surface rounded-card shadow-ambient overflow-hidden flex flex-col max-h-[70vh]"
      >
        {/* Search input */}
        <div className="flex items-center gap-3 px-5 py-4 border-b border-outline-variant/20">
          <SearchIcon className="text-outline !text-xl shrink-0" />
          <input
            ref={inputRef}
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder={t('search.placeholderAlt')}
            className="flex-1 bg-transparent text-on-surface placeholder:text-outline text-base focus:outline-none"
          />
          {query && (
            <button
              onClick={() => setQuery('')}
              className="text-outline hover:text-on-surface transition-colors"
              title={t('common.delete')}
            >
              <Close className="!text-lg" />
            </button>
          )}
          <kbd className="hidden sm:inline-flex h-6 px-2 items-center bg-surface-container text-outline text-label-sm rounded-md font-mono">
            Esc
          </kbd>
        </div>

        {/* Results */}
        <div className="flex-1 overflow-y-auto">
          {!debounced ? (
            <div className="px-5 py-12 text-center text-outline text-sm">
              {t('search.typeToSearch')}
            </div>
          ) : loading ? (
            <Loading size="sm" className="py-10" />
          ) : results.length === 0 ? (
            <div className="flex flex-col items-center justify-center gap-3 px-5 py-14 text-center">
              <div className="w-14 h-14 rounded-2xl bg-surface-container flex items-center justify-center text-outline">
                <SentimentDissatisfied className="!text-3xl" />
              </div>
              <div>
                <p className="text-sm font-semibold text-on-surface">{t('search.noResults')}</p>
                <p className="text-metadata text-outline mt-1">
                  {t('search.noResultsDesc', { query: debounced })}
                </p>
              </div>
            </div>
          ) : (
            <div className="py-2">
              {folders.length > 0 && (
                <SectionLabel>{t('search.folders', { count: folders.length })}</SectionLabel>
              )}
              {folders.map((f, i) => (
                <ResultRow
                  key={`f-${f.id}`}
                  active={activeIdx === i}
                  onClick={() => select({ kind: 'folder', data: f })}
                  onHover={() => setActiveIdx(i)}
                  icon={<FolderIcon className="!text-2xl text-primary" />}
                  title={highlight(f.name, debounced)}
                />
              ))}
              {files.length > 0 && (
                <SectionLabel>{t('search.files', { count: files.length })}</SectionLabel>
              )}
              {files.map((f, i) => {
                const idx = folders.length + i;
                return (
                  <ResultRow
                    key={`fl-${f.id}`}
                    active={activeIdx === idx}
                    onClick={() => select({ kind: 'file', data: f })}
                    onHover={() => setActiveIdx(idx)}
                    icon={
                      f.has_thumbnail && f.upload_status === 'done' ? (
                        <Thumbnail file={f} />
                      ) : (
                        <div className="!text-2xl">
                          <FileIcon mime={f.mime_type} />
                        </div>
                      )
                    }
                    title={highlight(f.name, debounced)}
                    subtitle={`${bytes(f.size)} • ${f.mime_type}`}
                  />
                );
              })}
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="flex items-center gap-3 px-5 py-2.5 border-t border-outline-variant/20 text-metadata text-outline">
          <span className="flex items-center gap-1">
            <kbd className="inline-flex h-5 px-1.5 items-center bg-surface-container text-label-sm rounded font-mono">↑</kbd>
            <kbd className="inline-flex h-5 px-1.5 items-center bg-surface-container text-label-sm rounded font-mono">↓</kbd>
            navigasi
          </span>
          <span className="flex items-center gap-1">
            <kbd className="inline-flex h-5 px-1.5 items-center bg-surface-container text-label-sm rounded font-mono">↵</kbd>
            buka
          </span>
        </div>
      </div>
    </div>
  );
}

function SectionLabel({ children }: { children: React.ReactNode }) {
  return (
    <div className="px-5 py-1.5 text-label-sm uppercase tracking-wider text-outline">
      {children}
    </div>
  );
}

function ResultRow({
  active,
  onClick,
  onHover,
  icon,
  title,
  subtitle,
}: {
  active: boolean;
  onClick: () => void;
  onHover: () => void;
  icon: React.ReactNode;
  title: React.ReactNode;
  subtitle?: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      onMouseEnter={onHover}
      className={clsx(
        'w-full flex items-center gap-3 px-5 py-2.5 text-left transition-colors',
        active ? 'bg-primary-container/30' : 'hover:bg-surface-container',
      )}
    >
      <div className="shrink-0 w-10 h-10 flex items-center justify-center overflow-hidden rounded-lg">
        {icon}
      </div>
      <div className="flex-1 min-w-0">
        <p className="text-sm text-on-surface font-medium truncate">{title}</p>
        {subtitle && <p className="text-metadata text-outline truncate">{subtitle}</p>}
      </div>
    </button>
  );
}

function highlight(text: string, q: string) {
  if (!q) return text;
  const idx = text.toLowerCase().indexOf(q.toLowerCase());
  if (idx === -1) return text;
  return (
    <>
      {text.slice(0, idx)}
      <mark className="bg-primary/30 text-on-surface rounded-sm">{text.slice(idx, idx + q.length)}</mark>
      {text.slice(idx + q.length)}
    </>
  );
}

function Thumbnail({ file }: { file: FileItem }) {
  const token = getToken();
  const src = `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/thumbnail${token ? `?token=${encodeURIComponent(token)}` : ''}`;
  return (
    // eslint-disable-next-line @next/next/no-img-element
    <img
      src={src}
      alt={file.name}
      className="w-10 h-10 object-cover rounded-lg bg-surface-container"
    />
  );
}
