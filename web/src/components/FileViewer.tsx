'use client';

import { useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Close, ChevronLeft, ChevronRight, Download, ContentCopy, Check } from '@mui/icons-material';
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
  const url = fileUrl(file);
  const gdriveUrl = file.gdrive_file_id ? `https://drive.google.com/file/d/${file.gdrive_file_id}/preview` : null;

  return (
    <div className="flex-1 w-full h-full flex flex-col bg-surface-container-dark p-2" onClick={(e) => e.stopPropagation()}>
      <iframe
        src={gdriveUrl || url}
        className="w-full h-full border-0 rounded-lg bg-white"
        title={file.name}
      />
    </div>
  );
}

function OfficeViewer({ file }: { file: FileItem }) {
  const gdriveUrl = file.gdrive_file_id ? `https://drive.google.com/file/d/${file.gdrive_file_id}/preview` : null;
  const docsViewerUrl = `https://docs.google.com/gview?url=${encodeURIComponent(fileUrl(file))}&embedded=true`;
  const iframeSrc = gdriveUrl || docsViewerUrl;

  return (
    <div className="flex-1 w-full h-full flex flex-col p-2 bg-black/40" onClick={(e) => e.stopPropagation()}>
      <iframe
        src={iframeSrc}
        className="w-full h-full border-0 rounded-lg bg-white shadow-2xl"
        title={file.name}
        allow="autoplay"
      />
    </div>
  );
}

function renderSimpleMarkdown(md: string) {
  const lines = md.split('\n');
  const elements: ReactNode[] = [];
  let inCodeBlock = false;
  let codeBuffer: string[] = [];

  lines.forEach((line, idx) => {
    if (line.startsWith('```')) {
      if (inCodeBlock) {
        elements.push(
          <pre key={idx} className="my-3 p-3 bg-surface-container-highest rounded-lg font-mono text-xs overflow-x-auto text-on-surface">
            <code>{codeBuffer.join('\n')}</code>
          </pre>
        );
        codeBuffer = [];
        inCodeBlock = false;
      } else {
        inCodeBlock = true;
      }
      return;
    }

    if (inCodeBlock) {
      codeBuffer.push(line);
      return;
    }

    if (line.startsWith('# ')) {
      elements.push(<h1 key={idx} className="text-2xl font-bold text-on-surface mt-4 mb-2">{line.replace('# ', '')}</h1>);
    } else if (line.startsWith('## ')) {
      elements.push(<h2 key={idx} className="text-xl font-bold text-on-surface mt-4 mb-2">{line.replace('## ', '')}</h2>);
    } else if (line.startsWith('### ')) {
      elements.push(<h3 key={idx} className="text-lg font-semibold text-on-surface mt-3 mb-1">{line.replace('### ', '')}</h3>);
    } else if (line.startsWith('> ')) {
      elements.push(
        <blockquote key={idx} className="border-l-4 border-primary pl-3 my-2 italic text-outline">
          {line.replace('> ', '')}
        </blockquote>
      );
    } else if (line.startsWith('- ') || line.startsWith('* ')) {
      elements.push(
        <li key={idx} className="ml-5 list-disc text-on-surface text-sm my-0.5">
          {line.substring(2)}
        </li>
      );
    } else if (/^\d+\.\s/.test(line)) {
      elements.push(
        <li key={idx} className="ml-5 list-decimal text-on-surface text-sm my-0.5">
          {line.replace(/^\d+\.\s/, '')}
        </li>
      );
    } else if (line.trim() === '---' || line.trim() === '***') {
      elements.push(<hr key={idx} className="my-4 border-outline-variant/30" />);
    } else if (line.trim() === '') {
      elements.push(<div key={idx} className="h-2" />);
    } else {
      elements.push(
        <p key={idx} className="text-sm text-on-surface leading-relaxed my-1">
          {line}
        </p>
      );
    }
  });

  return elements;
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
          <div>{renderSimpleMarkdown(content)}</div>
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
        <span className="text-xs text-outline font-mono">{lines.length} lines • {bytes(file.size)}</span>
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
  const gdriveUrl = file.gdrive_file_id ? `https://drive.google.com/file/d/${file.gdrive_file_id}/preview` : null;

  if (gdriveUrl) {
    return (
      <div className="flex-1 w-full h-full p-2" onClick={(e) => e.stopPropagation()}>
        <iframe
          src={gdriveUrl}
          className="w-full h-full border-0 rounded-lg bg-white shadow-2xl"
          title={file.name}
        />
      </div>
    );
  }

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
        <Download className="!text-base" /> Download
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
    case 'office':   viewer = <OfficeViewer file={file} />; break;
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
