'use client';

import type { ReactNode } from 'react';

// All icons are rendered via the Material Symbols font (filled variant).
// This keeps visual output consistent across pages — no mix of MUI React
// SVG icons and Material Symbols glyphs.

const SYMBOL = 'material-symbols-outlined';

function makeIcon(symbol: string, size: 'base' | 'lg' | '4xl' = '4xl') {
  return function Icon({ className = '' }: { className?: string }) {
    return (
      <span className={`${SYMBOL} !text-${size} fill ${className}`}>{symbol}</span>
    );
  };
}

export const FolderIcon = makeIcon('folder');
export const FolderSpecialIcon = makeIcon('folder_special');
export const StarIcon = makeIcon('star', 'base');
export const StarBorderIcon = makeIcon('star_border', 'base');
export const CloudDoneIcon = makeIcon('cloud_done', 'base');
export const CloudOffIcon = makeIcon('cloud_off', 'base');
export const CloudIcon = makeIcon('cloud');
export const EditIcon = makeIcon('edit', 'base');
export const CheckIcon = makeIcon('check', 'base');
export const CloseIcon = makeIcon('close', 'base');
export const AddIcon = makeIcon('add', 'base');
export const RefreshIcon = makeIcon('refresh', 'base');
export const LinkOffIcon = makeIcon('link_off', 'base');
export const DriveFileMoveIcon = makeIcon('drive_file_move', 'base');
export const MoreVertIcon = makeIcon('more_vert', 'base');
export const ErrorIcon = makeIcon('error', 'base');
export const ErrorOutlineIcon = makeIcon('error_outline', 'base');
export const HourglassEmptyIcon = makeIcon('hourglass_empty', 'base');
export const CheckCircleIcon = makeIcon('check_circle', 'base');
export const DeleteIcon = () => (
  <svg
    width="18"
    height="18"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    strokeWidth="2"
    aria-hidden="true"
  >
    <polyline points="3 6 5 6 21 6"></polyline>
    <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
    <path d="M10 11v6M14 11v6"></path>
  </svg>
);

// Material Symbols names for file mime types. Same mapping as before but
// always rendered through the font — no MUI icon.
const FILE_ICON_MAP: Array<[RegExp, string]> = [
  [/^image\//, 'image'],
  [/^application\/pdf$/, 'picture_as_pdf'],
  [/^video\//, 'video_file'],
  [/^audio\//, 'audio_file'],
  [/zip|rar|tar|7z/, 'folder_zip'],
  [/^text\/|^application\/(json|xml)/, 'code'],
];

export function FileIcon({ mime, className = '' }: { mime: string; className?: string }) {
  for (const [re, sym] of FILE_ICON_MAP) {
    if (re.test(mime)) {
      return <span className={`${SYMBOL} !text-4xl fill ${className}`}>{sym}</span>;
    }
  }
  return <span className={`${SYMBOL} !text-4xl fill ${className}`}>description</span>;
}

export const IconSymbol = SYMBOL;
export type IconProps = { className?: string };
