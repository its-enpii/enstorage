'use client';

import { useEffect, useState, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { useTranslation } from 'react-i18next';
import { Close, ChevronLeft, ChevronRight, Download, ContentCopy, Check, Slideshow, Add, Remove, Fullscreen, FullscreenExit } from '@mui/icons-material';
import JSZip from 'jszip';
import { marked } from 'marked';
import type { FileItem } from '@/lib/api';
import { getToken } from '@/lib/api';
import { bytes } from '@/lib/format';
import { getFileDownloadUrl } from '@/lib/download';
import { DropdownMenu, type MenuItem } from '@/components/DropdownMenu';
import { Tabs } from '@/components/Tabs';
import { Button, IconButton, LinkButton, TextAction } from '@/components/Button';
import { Tooltip } from '@/components/Tooltip';

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
  // Browsers render AVIF; WebP is the default thumbnail format. Backends that
  // fall back to `application/octet-stream` for unknown extensions still need
  // image/video/audio files to open in their viewers rather than the generic
  // "other" card.
  if (/\.(webp|avif|svg|bmp|ico|tiff?)$/i.test(name)) return 'image';
  if (mime.startsWith('video/')) return 'video';
  if (/\.(mp4|webm|mkv|mov|m4v|avi|ogv|3gp|flv|wmv)$/i.test(name)) return 'video';
  if (mime.startsWith('audio/')) return 'audio';
  if (/\.(mp3|wav|ogg|flac|m4a|aac|opus|wma)$/i.test(name)) return 'audio';
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

/**
 * Image preview.
 *
 * The viewport is the visible area between the overlay header and the bottom
 * of the screen — NOT the natural size of the bitmap. The image is always
 * laid out with `object-contain` inside that box (max-w/max-h 100%), so a
 * 4000×3000 photo is disclosed whole on first open instead of being cropped.
 * Zoom never resizes the layout either: `scale()` is a visual transform on
 * top of the fitted image, and panning happens on the scroll container.
 *
 *   - default zoom = 1 (= "fit screen"); 1x always re-fits the screen
 *   - zoom in/out steps through 100 % → 300 %
 *   - at >100 % the container can be dragged (wheel is forwarded to zoom)
 */
const ZOOM_MIN = 1;
const ZOOM_MAX = 3;
const ZOOM_STEP = 0.25;

function ImageViewer({ file }: { file: FileItem }) {
  const { t } = useTranslation();
  const [zoom, setZoom] = useState(1);
  const [natural, setNatural] = useState<{ w: number; h: number } | null>(null);
  const [panning, setPanning] = useState(false);
  const viewportRef = useRef<HTMLDivElement | null>(null);
  const dragRef = useRef<{ x: number; y: number; left: number; top: number } | null>(null);

  const clamp = (z: number) => Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, Math.round(z * 100) / 100));
  const zoomIn = () => setZoom((z) => clamp(z + ZOOM_STEP));
  const zoomOut = () => setZoom((z) => clamp(z - ZOOM_STEP));
  const resetZoom = () => {
    setZoom(1);
    viewportRef.current?.scrollTo({ left: 0, top: 0 });
  };

  // Zoomed image must keep the focal point in view: scroll to the centre.
  useEffect(() => {
    const el = viewportRef.current;
    if (!el) return;
    el.scrollLeft = Math.max(0, (el.scrollWidth - el.clientWidth) / 2);
    el.scrollTop = Math.max(0, (el.scrollHeight - el.clientHeight) / 2);
  }, [zoom]);

  // Ctrl/⌘ + wheel and plain wheel both zoom while hovering the image.
  useEffect(() => {
    const el = viewportRef.current;
    if (!el) return;
    const onWheel = (e: WheelEvent) => {
      e.preventDefault();
      setZoom((z) => clamp(z + (e.deltaY < 0 ? ZOOM_STEP : -ZOOM_STEP)));
    };
    el.addEventListener('wheel', onWheel, { passive: false });
    return () => el.removeEventListener('wheel', onWheel);
  }, []);

  const onPointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
    if (zoom <= 1 || e.button !== 0) return;
    const el = viewportRef.current;
    if (!el) return;
    dragRef.current = { x: e.clientX, y: e.clientY, left: el.scrollLeft, top: el.scrollTop };
    setPanning(true);
    el.setPointerCapture?.(e.pointerId);
  };
  const onPointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const d = dragRef.current;
    const el = viewportRef.current;
    if (!d || !el) return;
    el.scrollLeft = d.left - (e.clientX - d.x);
    el.scrollTop = d.top - (e.clientY - d.y);
  };
  const endPan = () => {
    dragRef.current = null;
    setPanning(false);
  };

  return (
    <div
      // `h-full`: the flex item must not grow past the viewer body (a
      // `flex-1` box combined with tall absolute controls pushed its
      // height over the visible area, hiding the image under the header).
      className="relative flex-1 h-full min-h-0 w-full flex flex-col overflow-hidden"
      onClick={(e) => e.stopPropagation()}
      onDoubleClick={(e) => {
        e.stopPropagation();
        if (zoom > 1) resetZoom();
        else zoomIn();
      }}
    >
      {/*
        Height = overlay minus its header (measured ~3.5 rem on phones,
        4.5 rem from `sm`) and minus a safe bottom inset, so the fitted image
        can never be covered by the header or the zoom pill. `min-h-0` +
        `flex-1` make the viewport follow the actual available space.
      */}
      <div
        ref={viewportRef}
        data-testid="viewer-image-viewport"
        className={
          'flex-1 min-h-0 w-full overflow-auto overscroll-contain flex items-center justify-center p-3 sm:p-6 ' +
          (panning ? 'cursor-grabbing select-none' : zoom > 1 ? 'cursor-grab' : '')
        }
        onPointerDown={onPointerDown}
        onPointerMove={onPointerMove}
        onPointerUp={endPan}
        onPointerCancel={endPan}
      >
        {/* eslint-disable-next-line @next/next/no-img-element */}
        <img
          key={file.id}
          src={fileUrl(file)}
          alt={file.name}
          data-testid="viewer-image"
          draggable={false}
          onLoad={(e) => setNatural({ w: e.currentTarget.naturalWidth, h: e.currentTarget.naturalHeight })}
          style={{ transform: `scale(${zoom})` }}
          className="block max-w-full max-h-full w-auto h-auto object-contain rounded-lg select-none transition-transform duration-150 ease-out"
        />
      </div>
      <div
        data-testid="viewer-zoom-controls"
        className="absolute bottom-[calc(1rem+env(safe-area-inset-bottom))] left-1/2 -translate-x-1/2 z-20 flex items-center gap-1 bg-surface-container/80 backdrop-blur-md px-3 py-1.5 rounded-full border border-outline-variant/20 shadow-lg"
      >
        <IconButton onClick={zoomOut} aria-label={t('preview.zoomOut')} disabled={zoom <= ZOOM_MIN} className="!w-11 !h-11">
          <Remove className="!text-sm" />
        </IconButton>
        <TextAction
          onClick={resetZoom}
          size="sm"
          className="!px-2 font-mono min-w-14 text-center"
          aria-label={t('preview.resetZoom')}
        >
          {Math.round(zoom * 100)}%
        </TextAction>
        <IconButton onClick={zoomIn} aria-label={t('preview.zoomIn')} disabled={zoom >= ZOOM_MAX} className="!w-11 !h-11">
          <Add className="!text-sm" />
        </IconButton>
        {natural && (
          <span
            data-testid="viewer-image-dimensions"
            className="hidden sm:inline pl-2 ml-1 border-l border-outline-variant/30 text-outline text-xs font-mono tabular-nums"
          >
            {natural.w}×{natural.h}
          </span>
        )}
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

    const title = allTexts.length > 0 ? allTexts[0] : String(i + 1);
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
  const { t } = useTranslation();
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
        <p className="text-base font-medium">{t('preview.loadingPptx', { size: bytes(file.size) })}</p>
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
          <LinkButton
            variant="primary"
            size="pill"
            href={fileUrl(file).replace('?inline=1', '').replace('&inline=1', '')}
            leftIcon={<Download className="!text-base" />}
            className="!bg-primary !text-on-primary hover:!bg-primary/90 shadow-md"
          >
            {t('preview.downloadPptx')}
          </LinkButton>
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
        className="w-screen h-screen bg-media-backdrop flex items-center justify-center overflow-hidden relative" onTouchStart={handleTouchStart} onTouchMove={handleTouchMove} onTouchEnd={handleTouchEnd}
        onClick={(e) => e.stopPropagation()}
      >
        {current.images && current.images.length > 0 ? (
          /* eslint-disable-next-line @next/next/no-img-element */
          <img
            src={current.images[0]}
            alt={String(activeSlide + 1)}
            className="w-full h-full object-contain"
            draggable={false}
          />
        ) : (
          <div className="w-full h-full flex flex-col justify-center items-center p-8 sm:p-16 text-center bg-slide-canvas text-slide-ink">
            <h1 className="text-3xl sm:text-5xl md:text-6xl font-display font-extrabold leading-tight mb-4 sm:mb-8">
              {current.title}
            </h1>
            {current.texts.length > 0 && (
              <div className="flex flex-col gap-3 sm:gap-5 max-w-4xl">
                {current.texts.map((p, idx) => (
                  <p key={idx} className="text-slide-muted text-lg sm:text-2xl md:text-3xl leading-snug font-medium">
                    {p}
                  </p>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Exit button - subtle on hover */}
        <IconButton
          bare
          shape="circle"
          size="lg"
          onClick={toggleFullscreen}
          className="absolute top-4 right-4 z-30 !bg-media-backdrop/60 !text-on-media opacity-100 hover:!bg-media-backdrop/80 transition-colors duration-300 backdrop-blur"
          aria-label={t('preview.exitFullscreen')}
        >
          <FullscreenExit className="!text-xl" />
        </IconButton>
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
      <div className="flex-1 relative flex items-center justify-center p-4 sm:p-8 md:p-12 overflow-hidden bg-surface-container-lowest/40 min-h-0" onTouchStart={handleTouchStart} onTouchMove={handleTouchMove} onTouchEnd={handleTouchEnd}>
        {/* Mode Presentasi Button */}
        <Button
          variant="secondary"
          size="sm"
          onClick={toggleFullscreen}
          leftIcon={<Fullscreen className="!text-lg" />}
          className="absolute top-4 right-4 z-20 !px-3.5 !py-1.5 !h-auto backdrop-blur shadow-md"
          aria-label={t('preview.presentationMode')}
        >
          {t('preview.presentationMode')}
        </Button>

        {/* Pure Direct Slide Visual Canvas (No outer white box frame, no inner black padding bars) */}
        {current.images && current.images.length > 0 ? (
          /* eslint-disable-next-line @next/next/no-img-element */
          <img
            src={current.images[0]}
            alt={String(activeSlide + 1)}
            className="max-w-full max-h-[82vh] object-contain rounded-xl sm:rounded-2xl shadow-2xl select-none"
            draggable={false}
          />
        ) : (
          <div className="w-full max-w-4xl aspect-[16/9] bg-slide-canvas rounded-xl sm:rounded-2xl p-6 sm:p-10 shadow-2xl border border-outline-variant/20 flex flex-col justify-center items-center text-center overflow-hidden">
            <h1 className="text-xl sm:text-3xl md:text-4xl font-display font-extrabold text-slide-ink leading-tight mb-2 sm:mb-4">
              {current.title}
            </h1>
            {current.texts.length > 0 && (
              <div className="flex flex-col gap-1.5 sm:gap-2 max-w-xl overflow-hidden">
                {current.texts.map((p, idx) => (
                  <p key={idx} className="text-slide-muted text-xs sm:text-base md:text-lg leading-snug font-medium break-words">
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
        <div className="w-full md:w-64 lg:w-72 h-20 sm:h-24 md:h-full border-t md:border-t-0 md:border-l border-outline-variant/15 bg-surface-container-lowest/80 backdrop-blur-md flex flex-row md:flex-col shrink-0 overflow-hidden">
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
                    className={`h-full md:h-auto aspect-[16/9] w-24 sm:w-28 md:w-full bg-slide-canvas rounded-lg p-1 sm:p-1.5 shadow border-2 transition-all flex flex-col justify-center overflow-hidden ${
                      isActive
                        ? 'border-primary ring-2 ring-primary/30 scale-[1.02]'
                        : 'border-outline-variant/20 hover:border-outline-variant/60'
                    }`}
                  >
                    {s.images && s.images.length > 0 ? (
                      /* eslint-disable-next-line @next/next/no-img-element */
                      <img src={s.images[0]} alt={String(idx + 1)} className="w-full h-full object-contain rounded" />
                    ) : (
                      <div className="flex flex-col justify-center items-center text-center p-0.5">
                        <p className="text-[8px] sm:text-[10px] font-bold text-slide-ink line-clamp-2 leading-tight">
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
  const { t } = useTranslation();
  const [content, setContent] = useState<string>('');
  const [loading, setLoading] = useState(true);
  const [tab, setTab] = useState<'preview' | 'code'>('preview');

  useEffect(() => {
    fetch(fileUrl(file))
      .then((r) => r.text())
      .then(setContent)
      .catch(() => setContent(t('preview.errorText')))
      .finally(() => setLoading(false));
  }, [file.id, t]);

  if (loading) return <TextSurfaceLoading label={t('preview.loadingText')} />;

  const htmlContent = marked.parse(content, { gfm: true, breaks: true }) as string;

  // Single surface: the overlay is already the backdrop — no inner card/frame.
  return (
    <div
      data-testid="file-text-viewer"
      data-viewer-kind="markdown"
      className="flex-1 flex flex-col overflow-hidden min-h-0 h-full w-full max-w-5xl mx-auto px-4 sm:px-6 py-3 sm:py-4"
      onClick={(e) => e.stopPropagation()}
    >
      <div className="flex items-center mb-2 shrink-0">
        <Tabs
          tabs={[
            { value: 'preview', label: t('preview.tabPreview') },
            { value: 'code', label: t('preview.tabRawMarkdown') },
          ]}
          value={tab}
          onChange={(v: string) => setTab(v as 'preview' | 'code')}
        />
      </div>
      <div className="flex-1 min-h-0 overflow-auto">
        {tab === 'preview' ? (
          <div
            className="prose markdown-body max-w-none text-on-surface leading-relaxed text-sm"
            dangerouslySetInnerHTML={{ __html: htmlContent }}
          />
        ) : (
          <pre className="m-0 p-0 min-w-0 bg-transparent border-0 text-sm text-on-surface font-mono whitespace-pre-wrap [overflow-wrap:anywhere] break-words">{content}</pre>
        )}
      </div>
    </div>
  );
}

function CodeTextViewer({ file }: { file: FileItem }) {
  const { t } = useTranslation();
  const [content, setContent] = useState<string>('');
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    fetch(fileUrl(file))
      .then((r) => r.text())
      .then(setContent)
      .catch(() => setContent(t('preview.errorText')))
      .finally(() => setLoading(false));
  }, [file.id, t]);

  const copyToClipboard = () => {
    void navigator.clipboard.writeText(content);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  if (loading) return <TextSurfaceLoading label={t('preview.loadingText')} />;

  const lines = content.split('\n');

  // Single surface: no inner card/frame/padding — the overlay is the backdrop.
  return (
    <div
      data-testid="file-text-viewer"
      data-viewer-kind="code"
      className="flex-1 flex flex-col overflow-hidden min-h-0 h-full w-full max-w-5xl mx-auto px-4 sm:px-6 py-3 sm:py-4"
      onClick={(e) => e.stopPropagation()}
    >
      <div className="flex items-center justify-between gap-2 mb-2 shrink-0">
        <span className="text-xs text-outline font-mono truncate">
          {t('viewer.textViewerLabel')}
          <span className="sr-only">{t('preview.lineCount', { count: lines.length })}</span>
        </span>
        <Button
          variant="secondary"
          size="sm"
          onClick={copyToClipboard}
          className="!px-3 !py-1.5 !h-auto text-xs"
          leftIcon={copied ? <Check className="!text-sm text-primary" /> : <ContentCopy className="!text-sm" />}
        >
          {copied ? t('preview.copied') : t('preview.copy')}
        </Button>
      </div>
      <div className="flex-1 min-h-0 overflow-auto font-mono text-sm flex">
        <div className="select-none text-right pr-3 sm:pr-4 text-outline/40 border-r border-outline-variant/20 mr-3 sm:mr-4 font-mono text-xs leading-relaxed shrink-0">
          {lines.map((_, i) => (
            <div key={i}>{i + 1}</div>
          ))}
        </div>
        <pre className="m-0 p-0 min-w-0 bg-transparent border-0 text-on-surface whitespace-pre break-words leading-relaxed flex-1 overflow-x-auto">
          {content}
        </pre>
      </div>
    </div>
  );
}

function OtherViewer({ file, downloadUrl }: { file: FileItem; downloadUrl: string }) {
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
      <LinkButton
        variant="primary"
        size="pill"
        href={downloadUrl}
        download
        leftIcon={<Download className="!text-base" />}
        className="mt-2 !bg-primary !text-on-primary hover:!bg-primary/90 font-medium"
      >
        {t('preview.downloadFile')}
      </LinkButton>
    </div>
  );
}

const PLAIN_TEXT_MAX_BYTES = 2 * 1024 * 1024;
function isPlainText(file: FileItem): boolean {
  const mime = (file.mime_type.split(';')[0] ?? '').trim().toLowerCase();
  const name = file.name.toLowerCase();
  return (
    mime.startsWith('text/') ||
    mime === 'application/json' ||
    mime === 'application/xml' ||
    name.endsWith('.txt') ||
    name.endsWith('.log') ||
    name.endsWith('.csv') ||
    name.endsWith('.json') ||
    name.endsWith('.xml')
  );
}

function TextSurfaceLoading({ label }: { label: string }) {
  return (
    <div className="flex-1 flex items-center justify-center text-outline px-4" role="status">
      {label}
    </div>
  );
}

/**
 * Plain-text / code / log previews. The overlay is the only surface: the text
 * sits directly on it with symmetric horizontal padding (no inner card frame),
 * so the rendered chain is body > overlay > text container > PRE.
 */
function PlainTextViewer({ file, downloadUrl, onClose }: { file: FileItem; downloadUrl: string; onClose: () => void }) {
  const { t } = useTranslation();
  const [content, setContent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const [copied, setCopied] = useState(false);
  const tooLarge = file.size > PLAIN_TEXT_MAX_BYTES;

  useEffect(() => {
    if (tooLarge) {
      setLoading(false);
      return;
    }
    let cancelled = false;
    const controller = new AbortController();
    setContent(null);
    setError(null);
    setLoading(true);
    fetch(fileUrl(file), { signal: controller.signal })
      .then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.text();
      })
      .then((text) => {
        if (!cancelled) setContent(text);
      })
      .catch((err: unknown) => {
        if (cancelled || (err instanceof DOMException && err.name === 'AbortError')) return;
        setError(t('preview.errorText'));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
      controller.abort();
    };
  }, [file.id, tooLarge, t]);

  const copyToClipboard = () => {
    if (!content) return;
    void navigator.clipboard.writeText(content);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  let body: ReactNode;
  if (tooLarge) {
    body = (
      <div className="flex-1 flex flex-col items-center justify-center gap-2 text-center p-6">
        <span className="material-symbols-outlined !text-4xl text-outline">draft</span>
        <p className="text-on-surface font-medium">{t('preview.tooLargeText')}</p>
        <p className="text-outline text-sm">{bytes(file.size)}</p>
      </div>
    );
  } else if (loading) {
    body = <div className="flex-1 flex items-center justify-center text-outline">{t('preview.loadingText')}</div>;
  } else if (error) {
    body = (
      <div className="flex-1 flex flex-col items-center justify-center gap-2 text-center p-6">
        <span className="material-symbols-outlined !text-4xl text-error">error</span>
        <p className="text-on-surface font-medium">{error}</p>
        <p className="text-outline text-sm break-all">{file.name}</p>
      </div>
    );
  } else {
    // Single surface: transparent PRE, symmetric padding, no inner frame.
    body = (
      <pre className="m-0 p-0 min-w-0 flex-1 min-h-0 overflow-auto whitespace-pre-wrap [overflow-wrap:anywhere] break-words bg-transparent border-0 font-mono text-sm leading-relaxed text-on-surface">
        {content}
      </pre>
    );
  }

  return (
    <div
      data-testid="file-text-viewer"
      data-viewer-kind="plain"
      className="flex flex-col overflow-hidden min-h-0 h-full w-full max-w-5xl mx-auto px-4 sm:px-6 py-3 sm:py-4"
      onClick={(e) => e.stopPropagation()}
    >
      <div className="flex items-center justify-between gap-2 mb-2 shrink-0">
        <span className="text-xs text-outline font-mono truncate">{t('viewer.textViewerLabel')}</span>
        <div className="flex items-center gap-1.5">
          {content !== null && !tooLarge && (
            <Button
              variant="secondary"
              size="sm"
              onClick={copyToClipboard}
              className="!px-3 !py-1.5 !h-auto text-xs"
              leftIcon={copied ? <Check className="!text-sm text-primary" /> : <ContentCopy className="!text-sm" />}
            >
              {copied ? t('preview.copied') : t('preview.copy')}
            </Button>
          )}
          <LinkButton
            variant="secondary"
            size="sm"
            href={downloadUrl}
            download
            leftIcon={<Download className="!text-base" />}
            className="!px-3 !py-1.5 !h-auto text-xs"
          >
            {t('preview.downloadFile')}
          </LinkButton>
          <Tooltip label={t('preview.close')}>
            <IconButton
              size="xl"
              shape="circle"
              onClick={onClose}
              aria-label={t('preview.close')}
              className="!bg-surface-container/80 backdrop-blur-md border border-outline-variant/20 !text-on-surface hover:!bg-surface-container-highest"
            >
              <Close className="!text-xl" />
            </IconButton>
          </Tooltip>
        </div>
      </div>
      {body}
    </div>
  );
}

function FileViewerOverlay({ file, files, onClose, onNavigate, actions }: Props) {
  const { t } = useTranslation();
  const category = mimeCategory(file);
  const downloadUrl = file.download_url || getFileDownloadUrl(file);
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
    default:         viewer = isPlainText(file)
      ? <PlainTextViewer file={file} downloadUrl={downloadUrl} onClose={onClose} />
      : <OtherViewer file={file} downloadUrl={downloadUrl} />; break;
  }

  return (
    <div
      data-testid="file-viewer-overlay"
      className="fixed inset-0 z-viewer flex flex-col bg-background/90 backdrop-blur-md"
      onClick={onClose}
    >
      {/*
        Tombol X di pojok kanan-atas overlay. Header sudah punya tombol tutup
        untuk desktop; X ini selalu terlihat di pojok (termasuk di atas
        viewer media) supaya menutup tidak pernah bergantung pada header.
      */}
      <Tooltip label={t('preview.close')} placement="bottom">
        <IconButton
          shape="circle"
          size="xl"
          onClick={(e) => {
            e.stopPropagation();
            onClose();
          }}
          aria-label={t('preview.close')}
          data-testid="file-viewer-close"
          className="absolute top-2 right-2 sm:top-3 sm:right-3 z-20 !bg-surface-container/80 backdrop-blur-md border border-outline-variant/20 !text-on-surface hover:!bg-surface-container-highest shadow-lg"
        >
          <Close className="!text-xl" />
        </IconButton>
      </Tooltip>
      {/* Header */}
      <div className="flex items-center justify-between pl-3 pr-14 sm:pl-6 sm:pr-20 py-2 sm:py-3 shrink-0 border-b border-outline-variant/10">
        <div className="flex items-center gap-3 min-w-0">
          <p className="text-on-surface font-display text-base truncate">{file.name}</p>
          <span className="text-outline text-sm shrink-0">{bytes(file.size)}</span>
        </div>
        <div className="flex items-center gap-1 shrink-0">
          {actions && actions.length > 0 && (
            <DropdownMenu
              align="right"
              trigger={
                <Tooltip label={t('preview.menu')} placement="bottom">
                  <IconButton
                    bare
                    shape="circle"
                    size="xl"
                    aria-label={t('preview.menu')}
                  >
                    <span className="material-symbols-outlined !text-xl" aria-hidden>more_vert</span>
                  </IconButton>
                </Tooltip>
              }
              items={actions}
            />
          )}
          <Tooltip label={t('preview.close')} placement="bottom">
            <IconButton
              shape="circle"
              size="xl"
              onClick={onClose}
              aria-label={t('preview.close')}
              className="hidden sm:inline-flex !bg-surface-container/80 backdrop-blur-md border border-outline-variant/20 !text-on-surface hover:!bg-surface-container-highest"
            >
              <Close className="!text-xl" />
            </IconButton>
          </Tooltip>
        </div>
      </div>

      {/* Main Viewer Body */}
      <div className="flex-1 flex items-center justify-center min-h-0 relative">
        {hasNav && currentIndex > 0 && category !== 'office' && (
          <IconButton
            shape="circle"
            size="xl"
            onClick={(e) => {
              e.stopPropagation();
              onNavigate?.(files![currentIndex - 1]);
            }}
            className="absolute left-2 sm:left-4 z-10 !bg-surface-container/70 backdrop-blur-md border border-outline-variant/20 !text-on-surface hover:!bg-surface-container-highest shadow-lg"
            aria-label={t('preview.prev')}
          >
            <ChevronLeft />
          </IconButton>
        )}

        {viewer}

        {hasNav && currentIndex < files!.length - 1 && category !== 'office' && (
          <IconButton
            shape="circle"
            size="xl"
            onClick={(e) => {
              e.stopPropagation();
              onNavigate?.(files![currentIndex + 1]);
            }}
            className="absolute right-2 sm:right-4 z-10 !bg-surface-container/70 backdrop-blur-md border border-outline-variant/20 !text-on-surface hover:!bg-surface-container-highest shadow-lg"
            aria-label={t('preview.next')}
          >
            <ChevronRight />
          </IconButton>
        )}
      </div>
    </div>
  );
}

export function FileViewer(props: Props) {
  const [mounted, setMounted] = useState(false);
  useEffect(() => setMounted(true), []);
  // Portal ke ROOT (`document.body`), keluar dari stacking context
  // `relative z-10` milik AppShell — overlay tidak lagi terkunci di bawah
  // TopBar (z-40) / Sidebar (z-50) / drawer (z-70). `z-viewer` (token di
  // globals.css) menaruhnya di lapisan paling atas.
  if (!mounted || typeof document === 'undefined') return null;
  return createPortal(<FileViewerOverlay {...props} />, document.body);
}
