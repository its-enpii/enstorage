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

type SlideData = {
  id: number;
  title: string;
  texts: string[];
  images: string[];
};

async function parsePptxWithJSZip(buffer: ArrayBuffer): Promise<SlideData[]> {
  const zip = await JSZip.loadAsync(buffer);
  const slides: SlideData[] = [];
  const parser = new DOMParser();

  // Extract all media blobs from ppt/media/
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

  for (let i = 0; i < slideFiles.length; i++) {
    const slidePath = slideFiles[i];
    const xmlText = await zip.files[slidePath].async('text');
    const xmlDoc = parser.parseFromString(xmlText, 'application/xml');

    // Read slide relationships from ppt/slides/_rels/slideN.xml.rels
    const slideName = slidePath.split('/').pop();
    const relsPath = `ppt/slides/_rels/${slideName}.rels`;
    const relsMap = new Map<string, string>();

    if (zip.files[relsPath]) {
      const relsText = await zip.files[relsPath].async('text');
      const relsDoc = parser.parseFromString(relsText, 'application/xml');
      const relNodes = Array.from(relsDoc.getElementsByTagName('Relationship'));
      for (const rel of relNodes) {
        const rId = rel.getAttribute('Id');
        const target = rel.getAttribute('Target');
        if (rId && target) {
          const mediaFileName = target.split('/').pop() || target;
          relsMap.set(rId, mediaFileName);
        }
      }
    }

    // Extract text paragraphs
    const textNodes = Array.from(xmlDoc.getElementsByTagName('a:t'));
    const allTexts = textNodes.map((n) => n.textContent?.trim() ?? '').filter((t) => t.length > 0);

    const title = allTexts.length > 0 ? allTexts[0] : `Slide ${i + 1}`;
    const bodyTexts = allTexts.length > 1 ? allTexts.slice(1) : [];

    // Extract exact slide images referenced in this slide XML
    const slideImages: string[] = [];
    const blipNodes = Array.from(xmlDoc.getElementsByTagName('a:blip'));

    for (const blip of blipNodes) {
      const embedId = blip.getAttribute('r:embed') || blip.getAttribute('embed');
      if (embedId && relsMap.has(embedId)) {
        const mediaFileName = relsMap.get(embedId)!;
        const blobUrl = mediaMap.get(mediaFileName);
        if (blobUrl && !slideImages.includes(blobUrl)) {
          slideImages.push(blobUrl);
        }
      }
    }

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
  const [isFullscreen, setIsFullscreen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  const touchStartX = useRef<number | null>(null);
  const touchEndX = useRef<number | null>(null);

  const handleTouchStart = (e: React.TouchEvent) => {
    touchStartX.current = e.targetTouches[0].clientX;
  };

  const handleTouchMove = (e: React.TouchEvent) => {
    touchEndX.current = e.targetTouches[0].clientX;
  };

  const handleTouchEnd = () => {
    if (!touchStartX.current || !touchEndX.current) return;
    const distance = touchStartX.current - touchEndX.current;
    const isLeftSwipe = distance > 40;
    const isRightSwipe = distance < -40;

    if (isLeftSwipe && activeSlide < slides.length - 1) {
      setActiveSlide((s) => s + 1);
    } else if (isRightSwipe && activeSlide > 0) {
      setActiveSlide((s) => s - 1);
    }

    touchStartX.current = null;
    touchEndX.current = null;
  };

  useEffect(() => {
    let active = true;
    fetch(fileUrl(file))
      .then((r) => r.arrayBuffer())
      .then(async (buf) => {
        const parsed = await parsePptxWithJSZip(buf);
        if (active) {
          if (parsed.length > 0) setSlides(parsed);
          else setError('Dokumen PPTX tidak memiliki slide.');
        }
      })
      .catch((e) => {
        if (active) setError(e instanceof Error ? e.message : 'Gagal memuat presentasi PPTX.');
      })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [file.id]);

  useEffect(() => {
    const onFsChange = () => setIsFullscreen(!!document.fullscreenElement);
    document.addEventListener('fullscreenchange', onFsChange);
    return () => document.removeEventListener('fullscreenchange', onFsChange);
  }, []);

  useEffect(() => {
    if (slides.length === 0) return;
    const onSlideKey = (e: KeyboardEvent) => {
      if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') {
        setActiveSlide((s) => Math.max(0, s - 1));
      } else if (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === ' ') {
        setActiveSlide((s) => Math.min(slides.length - 1, s + 1));
      }
    };
    window.addEventListener('keydown', onSlideKey);
    return () => window.removeEventListener('keydown', onSlideKey);
  }, [slides.length]);

  const toggleFullscreen = () => {
    if (!containerRef.current) return;
    if (!document.fullscreenElement) {
      containerRef.current.requestFullscreen().catch(() => {});
    } else {
      document.exitFullscreen().catch(() => {});
    }
  };

  if (loading) {
    return (
      <div className="flex-1 flex flex-col items-center justify-center gap-3 text-outline">
        <span className="w-10 h-10 border-4 border-primary border-t-transparent rounded-full animate-spin" />
        <p className="text-base font-medium">Memuat Slide Presentasi ({bytes(file.size)})...</p>
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
          <p className="text-outline text-xs mb-4">{bytes(file.size)}</p>
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

  /* ?? Fullscreen Presentation Mode: 100% edge-to-edge ?? */
  if (isFullscreen) {
    return (
      <div
        ref={containerRef}
        className="w-screen h-screen bg-black flex items-center justify-center overflow-hidden relative" onTouchStart={handleTouchStart} onTouchMove={handleTouchMove} onTouchEnd={handleTouchEnd}
        onClick={(e) => e.stopPropagation()}
      >
        {current.images && current.images.length > 0 ? (
          /* eslint-disable-next-line @next/next/no-img-element */
          <img
            src={current.images[0]}
            alt={`Slide ${activeSlide + 1}`}
            className="w-full h-full object-contain"
            draggable={false}
          />
        ) : (
          <div className="w-full h-full flex flex-col justify-center items-center p-8 sm:p-16 text-center bg-white text-gray-900">
            <h1 className="text-3xl sm:text-5xl md:text-6xl font-display font-extrabold leading-tight mb-4 sm:mb-8">
              {current.title}
            </h1>
            {current.texts.length > 0 && (
              <div className="flex flex-col gap-3 sm:gap-5 max-w-4xl">
                {current.texts.map((p, idx) => (
                  <p key={idx} className="text-gray-700 text-lg sm:text-2xl md:text-3xl leading-snug font-medium">
                    {p}
                  </p>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Exit button - subtle on hover */}
        <button
          type="button"
          onClick={toggleFullscreen}
          className="absolute top-4 right-4 z-30 p-2.5 rounded-full bg-black/50 text-white opacity-0 hover:opacity-100 transition-opacity duration-300 backdrop-blur"
          title="Keluar Fullscreen (Esc)"
        >
          <FullscreenExit className="!text-xl" />
        </button>
      </div>
    );
  }

  /* ?? Normal Preview Mode: Clean Visual Slide Canvas ?? */
  return (
    <div
      ref={containerRef}
      className="flex-1 w-full h-full flex flex-col md:flex-row overflow-hidden bg-background"
      onClick={(e) => e.stopPropagation()}
    >
      {/* Main Slide Canvas Container */}
      <div className="flex-1 relative flex items-center justify-center p-4 sm:p-8 md:p-12 overflow-hidden bg-surface-container-dark/40 min-h-0" onTouchStart={handleTouchStart} onTouchMove={handleTouchMove} onTouchEnd={handleTouchEnd}>
        {/* Mode Presentasi Button */}
        <button
          type="button"
          onClick={toggleFullscreen}
          className="absolute top-4 right-4 z-20 flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl bg-surface-container-highest/80 backdrop-blur text-on-surface hover:bg-surface-container-highest transition-colors text-xs font-semibold shadow-md border border-outline-variant/20"
          title="Mode Presentasi Layar Penuh"
        >
          <Fullscreen className="!text-lg" />
          <span>Mode Presentasi</span>
        </button>

        {/* Pure Direct Slide Visual Canvas (No outer white box frame, no inner black padding bars) */}
        {current.images && current.images.length > 0 ? (
          /* eslint-disable-next-line @next/next/no-img-element */
          <img
            src={current.images[0]}
            alt={`Slide ${activeSlide + 1}`}
            className="max-w-full max-h-[82vh] object-contain rounded-xl sm:rounded-2xl shadow-2xl select-none"
            draggable={false}
          />
        ) : (
          <div className="w-full max-w-4xl aspect-[16/9] bg-white rounded-xl sm:rounded-2xl p-6 sm:p-10 shadow-2xl border border-outline-variant/20 flex flex-col justify-center items-center text-center overflow-hidden">
            <h1 className="text-xl sm:text-3xl md:text-4xl font-display font-extrabold text-gray-900 leading-tight mb-2 sm:mb-4">
              {current.title}
            </h1>
            {current.texts.length > 0 && (
              <div className="flex flex-col gap-1.5 sm:gap-2 max-w-xl overflow-hidden">
                {current.texts.map((p, idx) => (
                  <p key={idx} className="text-gray-700 text-xs sm:text-base md:text-lg leading-snug font-medium break-words">
                    {p}
                  </p>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {/* Thumbnail Sidebar */}
      {slides.length > 1 && (
        <div className="w-full md:w-64 lg:w-72 h-20 sm:h-24 md:h-full border-t md:border-t-0 md:border-l border-outline-variant/15 bg-surface-container-dark/80 backdrop-blur-md flex flex-row md:flex-col shrink-0 overflow-hidden">
          <div className="flex-1 overflow-x-auto md:overflow-y-auto p-2 sm:p-3 flex flex-row md:flex-col gap-2 sm:gap-3 items-center md:items-stretch">
            {slides.map((s, idx) => {
              const isActive = activeSlide === idx;
              return (
                <div
                  key={idx}
                  onClick={() => setActiveSlide(idx)}
                  className="flex items-center gap-1.5 sm:gap-2 cursor-pointer group shrink-0 h-full md:h-auto"
                >
                  <span
                    className={`text-[10px] sm:text-xs font-bold font-mono min-w-[14px] text-center md:text-right transition-colors ${
                      isActive ? 'text-primary' : 'text-outline group-hover:text-on-surface'
                    }`}
                  >
                    {idx + 1}
                  </span>
                  <div
                    className={`h-full md:h-auto aspect-[16/9] w-24 sm:w-28 md:w-full bg-white rounded-lg p-1 sm:p-1.5 shadow border-2 transition-all flex flex-col justify-center overflow-hidden ${
                      isActive
                        ? 'border-primary ring-2 ring-primary/30 scale-[1.02]'
                        : 'border-outline-variant/20 hover:border-outline-variant/60'
                    }`}
                  >
                    {s.images && s.images.length > 0 ? (
                      /* eslint-disable-next-line @next/next/no-img-element */
                      <img src={s.images[0]} alt={`Thumbnail ${idx + 1}`} className="w-full h-full object-contain rounded" />
                    ) : (
                      <div className="flex flex-col justify-center items-center text-center p-0.5">
                        <p className="text-[8px] sm:text-[10px] font-bold text-gray-900 line-clamp-2 leading-tight">
                          {s.title}
                        </p>
                      </div>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}
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
    <div className="flex-1 flex flex-col overflow-hidden min-h-0 h-full max-w-5xl w-full mx-auto p-4" onClick={(e) => e.stopPropagation()}>
      <div className="flex items-center justify-between mb-3 bg-surface-container/60 p-2 rounded-xl border border-outline-variant/20 shrink-0">
        <Tabs
          tabs={[
            { value: 'preview', label: 'Preview' },
            { value: 'code', label: 'Raw Markdown' },
          ]}
          value={tab}
          onChange={(v: string) => setTab(v as 'preview' | 'code')}
        />
      </div>
      <div className="flex-1 min-h-0 overflow-auto bg-surface-container/30 border border-outline-variant/20 rounded-2xl p-6 sm:p-8 shadow-inner">
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
    <div className="flex-1 flex flex-col overflow-hidden min-h-0 h-full max-w-5xl w-full mx-auto p-4" onClick={(e) => e.stopPropagation()}>
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
      <div className="flex-1 min-h-0 overflow-auto bg-surface-container-dark border border-outline-variant/20 rounded-2xl p-4 font-mono text-sm shadow-xl flex">
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
        <p className="text-outline text-sm">{bytes(file.size)} Ã¢â‚¬Â¢ {file.mime_type}</p>
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
      if (hasNav && category !== 'office' && e.key === 'ArrowLeft' && currentIndex > 0) onNavigate?.(files![currentIndex - 1]);
      if (hasNav && category !== 'office' && e.key === 'ArrowRight' && currentIndex < files!.length - 1) onNavigate?.(files![currentIndex + 1]);
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
        {hasNav && currentIndex > 0 && category !== 'office' && (
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

        {hasNav && currentIndex < files!.length - 1 && category !== 'office' && (
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




