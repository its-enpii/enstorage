'use client';

import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Close, ChevronLeft, ChevronRight, Download, ContentCopy, Check, Slideshow, Fullscreen } from '@mui/icons-material';
import JSZip from 'jszip';
import { marked } from 'marked';
import type { FileItem } from '@/lib/api';
import { getToken } from '@/lib/api';
import { bytes } from '@/lib/format';
import { DropdownMenu, type MenuItem } from '@/components/DropdownMenu';

type Props = {
  file: FileItem;
  files?: FileItem[];
  onClose: () => void;
  onNavigate?: (file: FileItem) => void;
  actions?: MenuItem[];
};

function fileUrl(file: FileItem): string {
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
    name.endsWith('.md') ||
    name.endsWith('.markdown') ||
    mime === 'text/markdown' ||
    mime === 'text/x-markdown'
  ) {
    return 'markdown';
  }

  const officeExts = ['.pptx', '.ppt', '.docx', '.doc', '.xlsx', '.xls'];
  const officeMimes = [
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-excel',
  ];
  if (officeExts.some((ext) => name.endsWith(ext)) || officeMimes.includes(mime)) {
    return 'office';
  }

  const codeExts = [
    '.js', '.ts', '.tsx', '.jsx', '.json', '.html', '.css', '.scss',
    '.py', '.java', '.c', '.cpp', '.h', '.cs', '.go', '.rs', '.php',
    '.rb', '.sh', '.sql', '.yaml', '.yml', '.xml', '.env', '.gitignore',
  ];
  if (codeExts.some((ext) => name.endsWith(ext))) {
    return 'code';
  }

  if (mime.startsWith('text/') || ['application/json', 'application/xml', 'application/javascript'].includes(mime)) {
    return 'text';
  }

  return 'other';
}

function ImageViewer({ file }: { file: FileItem }) {
  const [zoom, setZoom] = useState(1);
  return (
    <div className="flex-1 flex items-center justify-center overflow-auto p-4" onClick={(e) => e.stopPropagation()}>
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src={fileUrl(file)}
        alt={file.name}
        className="max-w-full max-h-full object-contain rounded-lg cursor-zoom-in select-none"
        style={{ transform: `scale(${zoom})`, transition: 'transform 0.2s' }}
        onClick={() => setZoom((z) => (z >= 3 ? 1 : z + 0.5))}
        draggable={false}
      />
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
      <div className="w-24 h-24 rounded-full bg-primary-container flex items-center justify-center">
        <span className="material-symbols-outlined !text-5xl fill text-on-primary-container">music_note</span>
      </div>
      <p className="text-on-surface font-display text-lg">{file.name}</p>
      <audio controls autoPlay className="w-full max-w-md" src={fileUrl(file)} />
    </div>
  );
}

function PdfViewer({ file }: { file: FileItem }) {
  const [blobUrl, setBlobUrl] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    fetch(fileUrl(file))
      .then((res) => res.blob())
      .then((blob) => {
        if (active) {
          const objectUrl = URL.createObjectURL(blob);
          setBlobUrl(objectUrl);
        }
      })
      .catch(() => {})
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
      if (blobUrl) URL.revokeObjectURL(blobUrl);
    };
  }, [file.id]);

  if (loading) {
    return <div className="flex-1 flex items-center justify-center text-outline">Memuat dokumen PDF...</div>;
  }

  return (
    <div className="flex-1 w-full h-full p-4 flex items-center justify-center overflow-hidden" onClick={(e) => e.stopPropagation()}>
      {blobUrl ? (
        <object data={blobUrl} type="application/pdf" className="w-full h-full rounded-xl shadow-2xl bg-white">
          <embed src={blobUrl} type="application/pdf" className="w-full h-full border-0 rounded-xl" />
        </object>
      ) : (
        <div className="text-center">
          <p className="text-outline mb-2">Gagal memuat preview PDF.</p>
          <a href={fileUrl(file)} className="px-4 py-2 bg-primary text-on-primary rounded-full text-sm inline-flex items-center gap-2">
            <Download className="!text-sm" /> Download PDF
          </a>
        </div>
      )}
    </div>
  );
}

type SlideData = {
  id: number;
  title: string;
  texts: string[];
  images: string[];
};

async function parsePptxWithJSZip(buffer: ArrayBuffer): Promise<SlideData[]> {
  const zip = await JSZip.loadAsync(buffer);
  const slides: SlideData[] = [];

  // Extract images from ppt/media/
  const mediaMap = new Map<string, string>();
  const mediaFiles = Object.keys(zip.files).filter((path) => path.startsWith('ppt/media/'));

  for (const mediaPath of mediaFiles) {
    const file = zip.files[mediaPath];
    if (!file || file.dir) continue;
    const blob = await file.async('blob');
    const url = URL.createObjectURL(blob);
    const filename = mediaPath.split('/').pop() || mediaPath;
    mediaMap.set(filename, url);
    mediaMap.set(mediaPath, url);
  }

  // Find slide XML files: ppt/slides/slide1.xml, slide2.xml, ...
  const slideFiles = Object.keys(zip.files)
    .filter((path) => /^ppt\/slides\/slide\d+\.xml$/i.test(path))
    .sort((a, b) => {
      const numA = parseInt(a.match(/\d+/)?.[0] ?? '0', 10);
      const numB = parseInt(b.match(/\d+/)?.[0] ?? '0', 10);
      return numA - numB;
    });

  const parser = new DOMParser();

  for (let i = 0; i < slideFiles.length; i++) {
    const slidePath = slideFiles[i];
    const xmlText = await zip.files[slidePath].async('text');
    const xmlDoc = parser.parseFromString(xmlText, 'application/xml');

    const textNodes = Array.from(xmlDoc.getElementsByTagName('a:t'));
    const allTexts = textNodes.map((n) => n.textContent?.trim() ?? '').filter((t) => t.length > 0);

    const title = allTexts.length > 0 ? allTexts[0] : `Slide ${i + 1}`;
    const bodyTexts = allTexts.length > 1 ? allTexts.slice(1) : [];

    const slideImages: string[] = [];
    mediaMap.forEach((imgUrl) => {
      if (slideImages.length < 2) slideImages.push(imgUrl);
    });

    slides.push({
      id: i + 1,
      title,
      texts: bodyTexts,
      images: slideImages,
    });
  }

  return slides;
}

function PptxSlidePresenter({ file }: { file: FileItem }) {
  const [slides, setSlides] = useState<SlideData[]>([]);
  const [activeSlide, setActiveSlide] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    fetch(fileUrl(file))
      .then((r) => r.arrayBuffer())
      .then(async (buf) => {
        const parsed = await parsePptxWithJSZip(buf);
        if (active) {
          if (parsed.length > 0) {
            setSlides(parsed);
          } else {
            setError('Dokumen PPTX tidak memiliki slide.');
          }
        }
      })
      .catch((e) => {
        if (active) setError(e instanceof Error ? e.message : 'Gagal memuat presentasi PPTX.');
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, [file.id]);

  if (loading) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center gap-3 text-outline">
        <span className="w-8 h-8 border-3 border-primary border-t-transparent rounded-full animate-spin" />
        <p className="text-sm font-medium">Membaca & Merender Slide Presentasi PPTX ({bytes(file.size)})...</p>
      </div>
    );
  }

  if (error || slides.length === 0) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center gap-4 p-6" onClick={(e) => e.stopPropagation()}>
        <div className="w-20 h-20 rounded-2xl bg-surface-container flex items-center justify-center text-primary">
          <Slideshow className="!text-5xl" />
        </div>
        <div className="text-center max-w-md">
          <p className="text-on-surface font-semibold text-lg mb-1">{file.name}</p>
          <p className="text-outline text-xs mb-4">{bytes(file.size)} � {file.mime_type}</p>
          <a
            href={fileUrl(file).replace('?inline=1', '').replace('&inline=1', '')}
            className="inline-flex items-center gap-2 px-5 py-2.5 bg-primary text-on-primary rounded-full text-sm font-medium shadow-md"
          >
            <Download className="!text-base" /> Download PPTX
          </a>
        </div>
      </div>
    );
  }

  const current = slides[activeSlide];

  return (
    <div className="flex-1 w-full h-full flex flex-col p-4 max-w-5xl mx-auto overflow-hidden" onClick={(e) => e.stopPropagation()}>
      {/* Slide Canvas Card */}
      <div className="flex-1 bg-surface-container-lowest border border-outline-variant/20 rounded-3xl p-8 sm:p-12 shadow-2xl flex flex-col justify-between relative overflow-auto select-none">
        {/* Slide Title */}
        <div className="mb-6 border-b border-outline-variant/10 pb-4">
          <span className="text-xs uppercase tracking-wider font-semibold text-primary mb-2 block">
            Slide {activeSlide + 1} / {slides.length}
          </span>
          <h1 className="text-2xl sm:text-3xl font-display font-bold text-on-surface leading-tight break-words">
            {current.title}
          </h1>
        </div>

        {/* Slide Paragraphs */}
        <div className="flex-1 my-4 flex flex-col gap-3 overflow-y-auto max-h-[50vh]">
          {current.texts.length > 0 ? (
            current.texts.map((p, idx) => (
              <div key={idx} className="flex items-start gap-3 text-on-surface/90 text-base leading-relaxed">
                <span className="w-2 h-2 rounded-full bg-primary mt-2.5 shrink-0" />
                <p className="break-words">{p}</p>
              </div>
            ))
          ) : (
            <p className="text-outline italic text-sm">Slide Presentasi</p>
          )}

          {/* Embedded Slide Images */}
          {current.images && current.images.length > 0 && (
            <div className="flex items-center gap-4 mt-4 flex-wrap">
              {current.images.map((imgUrl, i) => (
                /* eslint-disable-next-line @next/next/no-img-element */
                <img key={i} src={imgUrl} alt={`Slide Image ${i + 1}`} className="max-h-48 rounded-xl object-contain shadow-md border border-outline-variant/20" />
              ))}
            </div>
          )}
        </div>

        {/* Slide Footer */}
        <div className="pt-4 border-t border-outline-variant/10 flex items-center justify-between text-xs text-outline font-mono">
          <span>{file.name}</span>
          <span>EnStorage Presentation Viewer</span>
        </div>
      </div>

      {/* Slide Navigation Bar */}
      <div className="flex items-center justify-between mt-4 px-2">
        <button
          type="button"
          disabled={activeSlide === 0}
          onClick={() => setActiveSlide((s) => Math.max(0, s - 1))}
          className="flex items-center gap-1 px-4 py-2 rounded-full bg-surface-container hover:bg-surface-container-highest disabled:opacity-30 text-on-surface transition-colors text-sm font-medium shadow-sm"
        >
          <ChevronLeft className="!text-lg" /> Previous
        </button>

        {/* Slide Thumbnails */}
        <div className="flex items-center gap-1.5 overflow-x-auto max-w-[50vw] px-2 py-1">
          {slides.map((_, i) => (
            <button
              key={i}
              type="button"
              onClick={() => setActiveSlide(i)}
              className={`w-8 h-8 rounded-xl text-xs font-semibold flex items-center justify-center transition-all ${
                activeSlide === i
                  ? 'bg-primary text-on-primary shadow-md scale-110'
                  : 'bg-surface-container text-outline hover:text-on-surface'
              }`}
            >
              {i + 1}
            </button>
          ))}
        </div>

        <button
          type="button"
          disabled={activeSlide === slides.length - 1}
          onClick={() => setActiveSlide((s) => Math.min(slides.length - 1, s + 1))}
          className="flex items-center gap-1 px-4 py-2 rounded-full bg-surface-container hover:bg-surface-container-highest disabled:opacity-30 text-on-surface transition-colors text-sm font-medium shadow-sm"
        >
          Next <ChevronRight className="!text-lg" />
        </button>
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

  const htmlContent = marked.parse(content) as string;

  return (
    <div className="flex-1 flex flex-col overflow-hidden max-w-4xl w-full mx-auto p-4" onClick={(e) => e.stopPropagation()}>
      <div className="flex items-center justify-between mb-3 bg-surface-container/60 p-2 rounded-xl border border-outline-variant/20">
        <div className="flex gap-1 bg-surface p-1 rounded-lg">
          <button
            type="button"
            onClick={() => setTab('preview')}
            className={`px-3 py-1 text-xs font-medium rounded-md transition-colors ${
              tab === 'preview' ? 'bg-primary text-on-primary' : 'text-outline hover:text-on-surface'
            }`}
          >
            Preview
          </button>
          <button
            type="button"
            onClick={() => setTab('code')}
            className={`px-3 py-1 text-xs font-medium rounded-md transition-colors ${
              tab === 'code' ? 'bg-primary text-on-primary' : 'text-outline hover:text-on-surface'
            }`}
          >
            Raw Markdown
          </button>
        </div>
      </div>
      <div className="flex-1 overflow-auto bg-surface-container/30 border border-outline-variant/20 rounded-2xl p-6 shadow-inner">
        {tab === 'preview' ? (
          <div
            className="prose dark:prose-invert max-w-none text-on-surface leading-relaxed text-sm"
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
        <span className="text-xs text-outline font-mono">{lines.length} lines � {bytes(file.size)}</span>
        <button
          type="button"
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
  return (
    <div className="flex-1 flex flex-col items-center justify-center gap-4 p-4" onClick={(e) => e.stopPropagation()}>
      <div className="w-24 h-24 rounded-full bg-surface-container flex items-center justify-center">
        <span className="material-symbols-outlined !text-5xl fill text-primary">description</span>
      </div>
      <div className="text-center">
        <p className="text-on-surface font-display text-lg mb-1">{file.name}</p>
        <p className="text-outline text-sm">{bytes(file.size)} � {file.mime_type}</p>
      </div>
      <a
        href={`${fileUrl(file).replace('?inline=1', '').replace('&inline=1', '')}`}
        className="mt-2 flex items-center gap-2 px-4 py-2 bg-primary text-on-primary rounded-full hover:bg-primary/90 transition-colors text-sm"
      >
        <Download className="!text-base" /> Download File
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
      <div className="flex items-center justify-between px-6 py-3 shrink-0 border-b border-outline-variant/10">
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
            <Close />
          </button>
        </div>
      </div>

      {/* Content */}
      {viewer}

      {/* Navigation */}
      {hasNav && (
        <>
          {currentIndex > 0 && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                onNavigate?.(files![currentIndex - 1]);
              }}
              className="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-surface-container-highest/80 flex items-center justify-center text-on-surface hover:bg-surface-container-highest transition-colors shadow-lg"
            >
              <ChevronLeft />
            </button>
          )}
          {currentIndex < files!.length - 1 && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                onNavigate?.(files![currentIndex + 1]);
              }}
              className="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-surface-container-highest/80 flex items-center justify-center text-on-surface hover:bg-surface-container-highest transition-colors shadow-lg"
            >
              <ChevronRight />
            </button>
          )}
        </>
      )}
    </div>
  );
}
