'use client';

import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  apiRequest,
  apiRequestEnvelope,
  ApiError,
  getToken,
  type FileItem,
  type Folder as FolderType,
} from '@/lib/api';
import { bytes, fileExtension } from '@/lib/format';
import { Modal } from '@/components/Modal';
import { Button } from '@/components/Button';
import { IconButton } from '@/components/Button';
import { SearchInput } from '@/components/SearchInput';
import { EmptyState } from '@/components/EmptyState';
import { Alert } from '@/components/Alert';
import { Spinner } from '@/components/Spinner';
import { FileCard } from '@/components/FileCard';
import { FolderCard } from '@/components/FolderCard';

type Props = {
  open: boolean;
  /** Folder terkunci aktif (target adopt). */
  targetFolderId: string;
  onClose: () => void;
  /** Dipanggil setelah server membalas sukses; `count` = item yang dipindah. */
  onAdopted: (result: AdoptResult) => void;
};

export type AdoptResult = {
  moved: string[];
  renamed: Array<{ id: string; name: string }>;
  not_found: string[];
  count: number;
};

type Crumb = { id: string | null; name: string };

const PAGE_LIMIT = 100;

function fileThumbnailUrl(file: FileItem): string | null {
  if (!file.has_thumbnail || file.upload_status !== 'done') return null;
  const token = getToken();
  return `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/thumbnail${token ? `?token=${encodeURIComponent(token)}` : ''}`;
}

/**
 * Dialog besar "Masukkan file" — HANYA dibuka dari dalam folder terkunci yang
 * sudah di-unlock. Menjelajah seluruh isi akun (kecuali subtree terkunci),
 * memilih file/folder via checkbox, lalu `POST /folders/{id}/adopt`.
 */
export function AdoptItemsDialog({ open, targetFolderId, onClose, onAdopted }: Props) {
  const { t } = useTranslation();
  const [crumbs, setCrumbs] = useState<Crumb[]>([{ id: null, name: t('files.title') }]);
  const [files, setFiles] = useState<FileItem[]>([]);
  const [folders, setFolders] = useState<FolderType[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [selectedFiles, setSelectedFiles] = useState<Set<string>>(new Set());
  const [selectedFolders, setSelectedFolders] = useState<Set<string>>(new Set());
  const [submitting, setSubmitting] = useState(false);

  const currentId = crumbs[crumbs.length - 1]?.id ?? null;

  // Reset seluruh state setiap dialog dibuka lagi.
  useEffect(() => {
    if (!open) return;
    setCrumbs([{ id: null, name: t('files.title') }]);
    setSearch('');
    setSelectedFiles(new Set());
    setSelectedFolders(new Set());
    setError(null);
    setSubmitting(false);
  }, [open, t]);

  const load = useCallback(async (folderId: string | null) => {
    setLoading(true);
    setError(null);
    try {
      const [fileRes, folderRes] = await Promise.all([
        apiRequestEnvelope<FileItem[]>('/files', {
          query: {
            folder_id: folderId ?? 'null',
            per_page: String(PAGE_LIMIT),
            ...(search ? { search } : {}),
          },
        }),
        apiRequestEnvelope<FolderType[]>('/folders', {
          query: { parent_id: folderId ?? 'null', per_page: String(PAGE_LIMIT) },
        }),
      ]);
      setFiles((fileRes.data ?? []).filter((f) => f.upload_status === 'done'));
      // Folder terkunci lain tidak boleh muncul di penjelajah.
      setFolders((folderRes.data ?? []).filter((f) => !f.is_locked));
    } catch (e) {
      setFiles([]);
      setFolders([]);
      setError(e instanceof ApiError ? e.message : t('files.errors.generic'));
    } finally {
      setLoading(false);
    }
  }, [search, t]);

  useEffect(() => {
    if (!open) return;
    void load(currentId);
    // Hanya muat ulang saat folder aktif / kata kunci berubah.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, currentId, search]);

  const selectedCount = selectedFiles.size + selectedFolders.size;

  function toggleFile(id: string, checked: boolean) {
    setSelectedFiles((prev) => toggle(prev, id, checked));
  }

  function toggleFolder(id: string, checked: boolean) {
    setSelectedFolders((prev) => toggle(prev, id, checked));
  }

  function enterFolder(folder: FolderType) {
    setSearch('');
    setCrumbs((prev) => [...prev, { id: folder.id, name: folder.name }]);
  }

  function goToCrumb(index: number) {
    setSearch('');
    setCrumbs((prev) => prev.slice(0, index + 1));
  }

  function goUp() {
    if (crumbs.length <= 1) return;
    setSearch('');
    setCrumbs((prev) => prev.slice(0, -1));
  }

  async function submit() {
    if (selectedCount === 0) return;
    setSubmitting(true);
    setError(null);
    try {
      const res = await apiRequest<AdoptResult>(`/folders/${targetFolderId}/adopt`, {
        method: 'POST',
        body: {
          file_ids: Array.from(selectedFiles),
          folder_ids: Array.from(selectedFolders),
        },
      });
      onAdopted(res);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : t('files.errors.generic'));
    } finally {
      setSubmitting(false);
    }
  }

  const items = useMemo(
    () => [
      ...folders.map((f) => ({ kind: 'folder' as const, item: f })),
      ...files.map((f) => ({ kind: 'file' as const, item: f })),
    ],
    [folders, files],
  );

  return (
    <Modal open={open} onClose={submitting ? () => {} : onClose} panelClassName="flex flex-col p-4 sm:p-6 lg:p-inner-padding !max-w-[min(1400px,96vw)] h-[92dvh] sm:h-[94dvh]">
      <div className="flex items-start gap-4 shrink-0">
        <div className="w-10 h-10 sm:w-12 sm:h-12 shrink-0 rounded-2xl bg-primary-container flex items-center justify-center text-on-primary-container">
          <span className="material-symbols-outlined !text-2xl">drive_file_move</span>
        </div>
        <div className="flex-1 min-w-0">
          <h2 className="font-display text-xl sm:text-headline-lg-mobile text-on-surface mb-1">
            {t('files.move.adoptTitle')}
          </h2>
          <p className="text-metadata text-on-surface-variant leading-relaxed">
            {t('files.move.adoptDescription')}
          </p>
        </div>
        <IconButton aria-label={t('common.close')} onClick={onClose} disabled={submitting}>
          <span className="material-symbols-outlined !text-xl">close</span>
        </IconButton>
      </div>

      {/* Breadcrumb + naik */}
      <div className="mt-4 flex items-center gap-2 shrink-0 min-w-0">
        <IconButton aria-label={t('files.move.adoptUp')} onClick={goUp} disabled={crumbs.length <= 1 || loading}>
          <span className="material-symbols-outlined !text-xl">arrow_upward</span>
        </IconButton>
        <div className="flex items-center gap-1 min-w-0 overflow-x-auto text-sm" data-testid="adopt-breadcrumb">
          {crumbs.map((c, i) => (
            <span key={`${c.id ?? 'root'}-${i}`} className="flex items-center gap-1 shrink-0">
              {i > 0 && <span className="material-symbols-outlined !text-base text-outline">chevron_right</span>}
              <button
                type="button"
                className="truncate max-w-[12rem] text-on-surface-variant hover:text-primary font-medium"
                onClick={() => goToCrumb(i)}
                disabled={i === crumbs.length - 1}
              >
                {c.name}
              </button>
            </span>
          ))}
        </div>
      </div>

      <div className="mt-3 shrink-0">
        <SearchInput
          placeholder={t('files.move.adoptSearch')}
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      <div className="mt-3 flex-1 min-h-0 overflow-y-auto rounded-2xl border border-outline-variant/30 bg-surface-container-low">
        {loading ? (
          <div className="flex items-center justify-center py-10">
            <Spinner />
          </div>
        ) : items.length === 0 ? (
          <EmptyState
            variant="panel"
            icon={<span className="material-symbols-outlined !text-3xl">folder_off</span>}
            title={t('files.move.adoptEmpty')}
            className="m-2"
          />
        ) : (
          <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2 p-2">
            {items.map(({ kind, item }) => {
              if (kind === 'folder') {
                const folder = item;
                const checked = selectedFolders.has(folder.id);
                return (
                  <FolderCard
                    key={`folder-${folder.id}`}
                    name={folder.name}
                    description={folder.description}
                    iconName={folder.icon}
                    logoUrl={folder.logo_url}
                    isStarred={folder.is_starred}
                    isLocked={folder.is_locked}
                    itemCount={(folder.files_count ?? 0) + (folder.folders_count ?? 0)}
                    itemsLabel={t('folders.items')}
                    totalSize={folder.total_size && folder.total_size > 0 ? bytes(folder.total_size) : undefined}
                    selected={checked}
                    selectMode
                    onClick={() => toggleFolder(folder.id, !checked)}
                    actions={
                      <IconButton
                        aria-label={t('files.move.adoptOpen')}
                        onClick={(e) => {
                          e.stopPropagation();
                          enterFolder(folder);
                        }}
                      >
                        <span className="material-symbols-outlined !text-xl">chevron_right</span>
                      </IconButton>
                    }
                  />
                );
              }
              const file = item;
              const checked = selectedFiles.has(file.id);
              return (
                <FileCard
                  key={`file-${file.id}`}
                  name={file.name}
                  extension={fileExtension(file.name)}
                  mimeType={file.mime_type}
                  size={bytes(file.size)}
                  thumbnailUrl={fileThumbnailUrl(file)}
                  metadata={file.metadata}
                  isStarred={file.is_starred}
                  uploadStatus={file.upload_status}
                  selected={checked}
                  selectMode
                  onClick={() => toggleFile(file.id, !checked)}
                />
              );
            })}
          </div>
        )}
      </div>

      {error && (
        <div className="mt-3 shrink-0">
          <Alert tone="danger">{error}</Alert>
        </div>
      )}

      <div className="mt-4 flex flex-col-reverse sm:flex-row gap-2.5 sm:gap-2 justify-between sm:items-center shrink-0 [&>button]:w-full sm:[&>button]:w-auto">
        <p className="text-sm text-on-surface-variant font-medium self-center">
          {t('files.move.adoptSelected', { count: selectedCount })}
        </p>
        <div className="flex flex-col-reverse sm:flex-row gap-2.5 sm:gap-2 [&>button]:w-full sm:[&>button]:w-auto">
          <Button variant="ghost" size="md" onClick={onClose} disabled={submitting}>
            {t('common.cancel')}
          </Button>
          <Button
            variant="primary"
            size="md"
            loading={submitting}
            disabled={selectedCount === 0}
            onClick={() => void submit()}
            data-testid="adopt-submit"
          >
            {t('files.move.adoptSubmit', { count: selectedCount })}
          </Button>
        </div>
      </div>
    </Modal>
  );
}

function toggle(prev: Set<string>, id: string, checked: boolean): Set<string> {
  const next = new Set(prev);
  if (checked) next.add(id);
  else next.delete(id);
  return next;
}
