'use client';

import { useEffect, useState, useRef, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Close, ChevronLeft, ChevronRight, Download, ContentCopy, Check, Slideshow, Add, Remove, Fullscreen, FullscreenExit } from '@mui/icons-material';
import JSZip from 'jszip';
import { marked } from 'marked';
import type { FileItem } from '@/lib/api';
import { getToken } from '@/lib/api';
import { bytes } from '@/lib/format';
import { DropdownMenu, type MenuItem } from '@/components/DropdownMenu';
import { Tabs } from '@/components/Tabs';
import { Button, IconButton } from '@/components/Button';

type Props = {
  file: FileItem;
  files?: FileItem[];
  onClose: () => void;
  onNavigate?: (file: FileItem) => void;
  actions?: MenuItem[];
};

function fileUrl(file: FileItem): string {
  if (file.stream_url) return file.stream_url;
  const token = getToken();
  const base = `${process.env.NEXT_PUBLIC_API_BASE}/files/${file.id}/download`;
  const params = new URLSearchParams({ inline: '1' });
  if (token) params.set('token', token);
  return `${base}?${params.toString()}`;
}

type MimeCategory = 'image' | 'video' | 'audio' | 'pdf' | 'office' | 'markdown' | 'code' | 'text' | 'other';

function mimeCategory(file: FileItem): MimeCategory {
  const mime = file.mime_type.toLowerCase();
  const name = file.name.toLowerCase();

  if (mime.startsWith('image/')) return 'image';
  if (mime.startsWith('video/')) return 'video';
  if (mime.startsWith('audio/')) return 'audio';
  if (mime === 'application/pdf' || name.endsWith('.pdf')) return 'pdf';
  if (
    name.endsWith('.pptx') ||
    name.endsWith('.ppt') ||
    name.endsWith('.docx') ||
    name.endsWith('.xlsx') ||
    mime.includes('presentationml') ||
    mime.includes('wordprocessingml') ||
    mime.includes('spreadsheetml')
  ) return 'office';
  if (
    name.endsWith('.md') ||
    name.endsWith('.markdown') ||
    mime === 'text/markdown' ||
    mime === 'text/x-markdown'
  ) return 'markdown';
  if (
    mime.startsWith('text/') ||
    mime === 'application/json' ||
    mime === 'application/xml' ||
    mime.includes('javascript') ||
    mime.includes('typescript')
  ) return 'code';

  return 'other';
}

function ImageViewer({ file }: { file: FileItem }) {
  const [scale, setScale] = useState(1);

  const zoomIn = () => setScale((s) => Math.min(s + 0.25, 3));
  const zoomOut = () => setScale((s) => Math.max(s - 0.25, 0.5));
  const resetZoom = () => setScale(1);

  return (
    <div className="relative flex-1 flex flex-col items-center justify-center p-4 overflow-hidden" onClick={(e) => e.stopPropagation()}>
      <div className="flex-1 flex items-center justify-center overflow-auto w-full h-full">
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          src={fileUrl(file)}
          alt={file.name}
          style={{ transform: `scale(${scale})` }}
          className="max-w-full max-h-full object-contain rounded-lg select-none transition-transform duration-150 ease-out"
        />
      </div>
      <div className="absolute bottom-4 left-1/2 -translate-x-1/2 flex items-center gap-1 bg-surface-container/80 backdrop-blur-md px-3 py-1.5 rounded-full border border-outline-variant/20 shadow-lg">
        <IconButton onClick={zoomOut} title="Zoom out" aria-label="Zoom out" className="!w-8 !h-8">
          <Remove className="!text-sm" />
        </IconButton>
        <button onClick={resetZoom} className="px-2 text-xs font-mono text-on-surface hover:text-primary transition-colors">
          {Math.round(scale * 100)}%
        </button>
        <IconButton onClick={zoomIn} title="Zoom in" aria-label="Zoom in" className="!w-8 !h-8">
          <Add className="!text-sm" />
        </IconButton>
      </div>
    </div>
  );
}

function VideoViewer({ file }: { file: FileItem }) {
  return (
    <div className="flex-1 flex items-center justify-center p-4" onClick={(e) => e.stopPropagation()}>
      <video controls autoPlay className="max-w-full max-h-full rounded-lg" src={fileUrl(file)} />
    </div>
  );
}

function AudioViewer({ file }: { file: FileItem }) {
  return (
    <div className="flex-1 flex flex-col items-center justify-center gap-6 p-4" onClick={(e) => e.stopPropagation()}>
      <div className="w-32 h-32 rounded-full bg-primary-container flex items-center justify-center text-on-primary-container shadow-ambient">
        <span className="material-symbols-outlined !text-6xl">graphic_eq</span>
      </div>
      <audio controls autoPlay className="w-full max-w-md" src={fileUrl(file)} />
    </div>
  );
}

function PdfViewer({ file }: { file: FileItem }) {
  return (
    <div className="flex-1 flex flex-col w-full h-full p-2 sm:p-4" onClick={(e) => e.stopPropagation()}>
      <iframe
        src={fileUrl(file)}
        className="w-full h-full rounded-xl border border-outline-variant/20 bg-surface"
        title={file.name}
      />
    </div>
  );
}

function PptxSlidePresenter({ file }: { file: FileItem }) {
  const { t } = useTranslation();
  const [slides, setSlides] = useState<string[]>([]);
  const [currentIndex, setCurrentIndex] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    let active = true;
    async function loadPresentation() {
      try {
        setLoading(true);
        setError(null);
        const res = await fetch(fileUrl(file));
        if (!res.ok) throw new Error('Gagal mengunduh file presentasi.');
        const buffer = await res.arrayBuffer();

        const zip = await JSZip.loadAsync(buffer);
        const slideFiles = Object.keys(zip.files)
          .filter((path) => /^ppt\/slides\/slide\d+\.xml$/i.test(path))
          .sort((a, b) => {
            const numA = parseInt(a.match(/\d+/)?[0] || '0', 10);
            const numB = parseInt(b.match(/\d+/)?[0] || '0', 10);
            return numA - numB;
          });

        if (slideFiles.length === 0) {
          throw new Error('Tidak ada slide yang ditemukan dalam file ini.');
        }

        const extractedTexts: string[] = [];
        for (const path of slideFiles) {
          const xmlText = await zip.files[path].async('string');
          const matches = xmlText.match(/<a:t[^>]*>(.*?)<\/a:t>/gi) || [];
          const textContent = matches
            .map((m) => m.replace(/<[^>]+>/g, ''))
            .filter((t) => t.trim().length > 0)
            .join(' ');
          extractedTexts.push(textContent || `[Slide ${extractedTexts.length + 1}]`);
        }

        if (active) {
          setSlides(extractedTexts);
          setCurrentIndex(0);
        }
      } catch (err: unknown) {
        if (active) {
          setError(err instanceof Error ? err.message : 'Gagal membaca presentasi.');
        }
      } finally {
        if (active) setLoading(false);
      }
    }
    loadPresentation();
    return () => {
      active = false;
    };
  }, [file.id]);

  useEffect(() => {
    const handleFullscreenChange = () => {
      setIsFullscreen(!!document.fullscreenElement);
    };
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    return () => document.removeEventListener('fullscreenchange', handleFullscreenChange);
  }, []);

  const toggleFullscreen = () => {
    if (!containerRef.current) return;
    if (!document.fullscreenElement) {
      containerRef.current.requestFullscreen().catch(() => {});
    } else {
      document.exitFullscreen().catch(() => {});
    }
  };

  const nextSlide = () => {
    if (currentIndex < slides.length - 1) setCurrentIndex((i) => i + 1);
  };
  const prevSlide = () => {
    if (currentIndex > 0) setCurrentIndex((i) => i - 1);
  };

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (slides.length === 0) return;
      if (e.key === 'ArrowRight' || e.key === 'Space') {
        e.preventDefault();
        nextSlide();
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        prevSlide();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [currentIndex, slides.length]);

  if (loading) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center gap-3 text-outline">
        <span className="w-8 h-8 border-2 border-primary border-t-transparent rounded-full animate-spin" />
        <p className="text-sm font-display">Memproses slide presentasi...</p>
      </div>
    );
  }

  if (error || slides.length === 0) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center gap-4 p-4" onClick={(e) => e.stopPropagation()}>
        <div className="w-20 h-20 rounded-full bg-error-container/30 flex items-center justify-center text-error">
          <Slideshow className="!text-4xl" />
        </div>
        <div className="text-center max-w-md">
          <p className="text-on-surface font-display text-base mb-1">{file.name}</p>
          <p className="text-outline text-xs mb-4">{error || 'Gagal mengekstrak slide.'}</p>
          <a
            href={`${fileUrl(file).replace('?inline=1', '').replace('&inline=1', '')}`}
            className="inline-flex items-center gap-2 px-4 py-2 bg-primary text-on-primary rounded-full hover:bg-primary/90 transition-colors text-xs font-semibold"
          >
            <Download className="!text-sm" /> Download PPTX
          </a>
        </div>
      </div>
    );
  }

  return (
    <div
      ref={containerRef}
      className="flex-1 flex flex-col items-center justify-between p-3 sm:p-6 w-full max-w-5xl mx-auto overflow-hidden"
      onClick={(e) => e.stopPropagation()}
    >
      <div className="w-full flex items-center justify-between px-2 py-1 text-xs text-outline font-mono">
        <div className="flex items-center gap-2">
          <Slideshow className="!text-sm text-primary" />
          <span className="truncate max-w-[200px] sm:max-w-xs">{file.name}</span>
        </div>
        <span>
          Slide {currentIndex + 1} / {slides.length}
        </span>
      </div>

      <div className="relative w-full flex-1 flex items-center justify-center my-2">
        <div className="w-full max-w-4xl aspect-[16/9] bg-surface rounded-xl sm:rounded-2xl p-6 sm:p-10 shadow-2xl border border-outline-variant/20 flex flex-col justify-center items-center text-center overflow-hidden">
          <div className="flex flex-col gap-3 sm:gap-5 max-w-4xl">
            <h2 className="text-lg sm:text-2xl font-display font-bold text-on-surface leading-tight tracking-tight">
              {slides[currentIndex]}
            </h2>
          </div>
        </div>

        {currentIndex > 0 && (
          <button
            onClick={prevSlide}
            className="absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-surface-container/80 backdrop-blur-md border border-outline-variant/30 text-on-surface hover:bg-primary-container hover:text-on-primary-container transition-all flex items-center justify-center shadow-lg"
            title="Slide sebelumnya (Panah Kiri)"
          >
            <ChevronLeft className="!text-xl" />
          </button>
        )}
        {currentIndex < slides.length - 1 && (
          <button
            onClick={nextSlide}
            className="absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-surface-container/80 backdrop-blur-md border border-outline-variant/30 text-on-surface hover:bg-primary-container hover:text-on-primary-container transition-all flex items-center justify-center shadow-lg"
            title="Slide selanjutnya (Panah Kanan / Spasi)"
          >
            <ChevronRight className="!text-xl" />
          </button>
        )}
      </div>

      <div className="flex items-center gap-3 bg-surface-container/80 backdrop-blur-md px-4 py-2 rounded-full border border-outline-variant/20 shadow-lg">
        <IconButton onClick={prevSlide} disabled={currentIndex === 0} title="Sebelumnya" aria-label="Sebelumnya">
          <ChevronLeft className="!text-sm" />
        </IconButton>
        <span className="text-xs font-mono text-on-surface min-w-[70px] text-center">
          {currentIndex + 1} of {slides.length}
        </span>
        <IconButton onClick={nextSlide} disabled={currentIndex === slides.length - 1} title="Selanjutnya" aria-label="Selanjutnya">
          <ChevronRight className="!text-sm" />
        </IconButton>
        <div className="w-px h-4 bg-outline-variant/30 mx-1" />
        <IconButton onClick={toggleFullscreen} title={isFullscreen ? 'Keluar Fullscreen' : 'Fullscreen'} aria-label="Fullscreen">
          {isFullscreen ? <FullscreenExit className="!text-sm" /> : <Fullscreen className="!text-sm" />}
        </IconButton>
      </div>
    </div>
  );
}

function MarkdownViewer({ file }: { file: FileItem }) {
  const [content, setContent] = useState<string>('');
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<'preview' | 'code'>('preview');

  useEffect(() => {
    fetch(fileUrl(file))
      .then((r) => r.text())
      .then(setContent)
      .catch(() => setContent('Gagal memuat konten markdown.'))
      .finally(() => setLoading(false));
  }, [file.id]);

  if (loading) return <div className="flex-1 flex items-center justify-center text-outline">Loading markdown...</div>;

  const htmlContent = marked.parse(content, { gfm: true, breaks: true }) as string;

  return (
    <div className="flex-1 flex flex-col overflow-hidden max-w-5xl w-full mx-auto p-4" onClick={(e) => e.stopPropagation()}>
      <div className="flex items-center justify-between mb-3 bg-surface-container/60 p-2 rounded-xl border border-outline-variant/20">
        <Tabs
          tabs={[
            { value: 'preview', label: 'Preview' },
            { value: 'code', label: 'Raw Markdown' },
          ]}
          value={tab}
          onChange={(v: string) => setTab(v as 'preview' | 'code')}
        />
      </div>
      <div className="flex-1 overflow-auto bg-surface-container/30 border border-outline-variant/20 rounded-2xl p-6 sm:p-8 shadow-inner">
        {tab === 'preview' ? (
          <div
            className="prose markdown-body max-w-none text-on-surface leading-relaxed text-sm"
            dangerouslySetInnerHTML={{ __html: htmlContent }}
          />
        ) : (
          <pre className="text-sm text-on-surface font-mono whitespace-pre-wrap break-words">{content}</pre>
        )}
      </div>
    </div>
  );
}

function CodeTextViewer({ file }: { file: FileItem }) {
  const [content, setContent] = useState<string>('');
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    fetch(fileUrl(file))
      .then((r) => r.text())
      .then(setContent)
      .catch(() => setContent('Gagal memuat konten.'))
      .finally(() => setLoading(false));
  }, [file.id]);

  const copyToClipboard = () => {
    void navigator.clipboard.writeText(content);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  if (loading) return <div className="flex-1 flex items-center justify-center text-outline">Loading text...</div>;

  const lines = content.split('\n');

  return (
    <div className="flex-1 flex flex-col overflow-hidden max-w-5xl w-full mx-auto p-4" onClick={(e) => e.stopPropagation()}>
      <div className="flex items-center justify-between mb-2">
        <span className="text-xs text-outline font-mono">{lines.length} lines</span>
        <button
          onClick={copyToClipboard}
          className="flex items-center gap-1 text-xs px-3 py-1.5 rounded-lg bg-surface-container hover:bg-surface-container-highest text-on-surface transition-colors border border-outline-variant/20"
        >
          {copied ? <Check className="!text-sm text-primary" /> : <ContentCopy className="!text-sm" />}
          {copied ? 'Copied' : 'Copy'}
        </button>
      </div>
      <div className="flex-1 overflow-auto bg-surface-container-dark border border-outline-variant/20 rounded-2xl p-4 font-mono text-sm shadow-xl flex">
        <div className="select-none text-right pr-4 text-outline/40 border-r border-outline-variant/20 mr-4 font-mono text-xs leading-relaxed">
          {lines.map((_, i) => (
            <div key={i}>{i + 1}</div>
          ))}
        </div>
        <pre className="text-on-surface whitespace-pre break-words leading-relaxed flex-1 overflow-x-auto">
          {content}
        </pre>
      </div>
    </div>
  );
}

function OtherViewer({ file }: { file: FileItem }) {
  const { t } = useTranslation();
  return (
    <div className="flex-1 flex flex-col items-center justify-center gap-4 p-4" onClick={(e) => e.stopPropagation()}>
      <div className="w-24 h-24 rounded-full bg-surface-container flex items-center justify-center">
        <span className="material-symbols-outlined !text-5xl fill text-primary">description</span>
      </div>
      <div className="text-center">
        <p className="text-on-surface font-display text-lg mb-1">{file.name}</p>
        <p className="text-outline text-sm">{bytes(file.size)} • {file.mime_type}</p>
      </div>
      <a
        href={`${fileUrl(file).replace('?inline=1', '').replace('&inline=1', '')}`}
        className="mt-2 flex items-center gap-2 px-4 py-2 bg-primary text-on-primary rounded-full hover:bg-primary/90 transition-colors text-sm"
      >
        <Download className="!text-base" /> {t('preview.downloadFile')}
      </a>
    </div>
  );
}

export function FileViewer({ file, files, onClose, onNavigate, actions }: Props) {
  const { t } = useTranslation();
  const category = mimeCategory(file);
  const currentIndex = files ? files.findIndex((f) => f.id === file.id) : -1;
  const hasNav = files && files.length > 1;

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      if (hasNav && e.key === 'ArrowLeft' && currentIndex > 0) onNavigate?.(files![currentIndex - 1]);
      if (hasNav && e.key === 'ArrowRight' && currentIndex < files!.length - 1) onNavigate?.(files![currentIndex + 1]);
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [onClose, onNavigate, hasNav, currentIndex, files]);

  let viewer: ReactNode;
  switch (category) {
    case 'image':    viewer = <ImageViewer file={file} />; break;
    case 'video':    viewer = <VideoViewer file={file} />; break;
    case 'audio':    viewer = <AudioViewer file={file} />; break;
    case 'pdf':      viewer = <PdfViewer file={file} />; break;
    case 'office':   viewer = <PptxSlidePresenter file={file} />; break;
    case 'markdown': viewer = <MarkdownViewer file={file} />; break;
    case 'code':
    case 'text':     viewer = <CodeTextViewer file={file} />; break;
    default:         viewer = <OtherViewer file={file} />; break;
  }

  return (
    <div
      className="fixed inset-0 z-[70] flex flex-col bg-background/90 backdrop-blur-md"
      onClick={onClose}
    >
      {/* Header */}
      <div className="flex items-center justify-between px-3 sm:px-6 py-2 sm:py-3 shrink-0 border-b border-outline-variant/10">
        <div className="flex items-center gap-3 min-w-0">
          <p className="text-on-surface font-display text-base truncate">{file.name}</p>
          <span className="text-outline text-sm shrink-0">{bytes(file.size)}</span>
        </div>
        <div className="flex items-center gap-1 shrink-0">
          {actions && actions.length > 0 && (
            <DropdownMenu
              align="right"
              trigger={
                <button
                  className="w-10 h-10 flex items-center justify-center rounded-full text-outline hover:text-on-surface hover:bg-surface-container-highest transition-colors"
                  title={t('files.actions.menu')}
                  aria-label={t('preview.menu')}
                >
                  <span className="material-symbols-outlined !text-xl">more_vert</span>
                </button>
              }
              items={actions}
            />
          )}
          <button
            onClick={onClose}
            className="w-10 h-10 flex items-center justify-center rounded-full text-outline hover:text-on-surface hover:bg-surface-container-highest transition-colors"
            title={t('preview.close')}
            aria-label={t('preview.close')}
          >
            <Close className="!text-xl" />
          </button>
        </div>
      </div>

      {/* Main Viewer Body */}
      <div className="flex-1 flex items-center justify-center min-h-0 relative">
        {hasNav && currentIndex > 0 && (
          <button
            onClick={(e) => {
              e.stopPropagation();
              onNavigate?.(files![currentIndex - 1]);
            }}
            className="absolute left-2 sm:left-4 z-10 w-11 h-11 rounded-full bg-surface-container/70 backdrop-blur-md border border-outline-variant/20 text-on-surface hover:bg-surface-container-highest transition-colors flex items-center justify-center shadow-lg"
            title={t('preview.prev')}
            aria-label={t('preview.prev')}
          >
            <ChevronLeft />
          </button>
        )}

        {viewer}

        {hasNav && currentIndex < files!.length - 1 && (
          <button
            onClick={(e) => {
              e.stopPropagation();
              onNavigate?.(files![currentIndex + 1]);
            }}
            className="absolute right-2 sm:right-4 z-10 w-11 h-11 rounded-full bg-surface-container/70 backdrop-blur-md border border-outline-variant/20 text-on-surface hover:bg-surface-container-highest transition-colors flex items-center justify-center shadow-lg"
            title={t('preview.next')}
            aria-label={t('preview.next')}
          >
            <ChevronRight />
          </button>
        )}
      </div>
    </div>
  );
}
