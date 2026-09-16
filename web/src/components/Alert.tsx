import clsx from 'clsx';
import type { ReactNode } from 'react';
import { Check, ErrorOutlined, InfoOutlined, WarningAmber } from '@mui/icons-material';

type Tone = 'danger' | 'warning' | 'success' | 'info';

const toneClass: Record<Tone, string> = {
  danger: 'bg-error-container/30 border-error/30 text-error',
  warning: 'bg-secondary-container/20 border-secondary/30 text-secondary',
  success: 'bg-primary-container/30 border-primary/30 text-primary',
  info: 'bg-surface-container border-outline-variant/20 text-on-surface-variant',
};

const toneIcon: Record<Tone, ReactNode> = {
  danger: <ErrorOutlined className="!text-lg shrink-0" />,
  warning: <WarningAmber className="!text-lg shrink-0" />,
  success: <Check className="!text-lg shrink-0" />,
  info: <InfoOutlined className="!text-lg shrink-0" />,
};

type Props = {
  children: ReactNode;
  tone?: Tone;
  icon?: ReactNode | false;
  className?: string;
};

/** Inline status banner (errors, warnings, confirmations) rendered inside a page or dialog. */
export function Alert({ children, tone = 'danger', icon, className }: Props) {
  const glyph = icon === false ? null : (icon ?? toneIcon[tone]);
  return (
    <div
      role={tone === 'danger' ? 'alert' : 'status'}
      className={clsx(
        'flex items-start gap-2 rounded-xl border px-4 py-2 text-sm',
        toneClass[tone],
        className,
      )}
    >
      {glyph && <span className="mt-0.5">{glyph}</span>}
      <div className="flex-1 min-w-0">{children}</div>
    </div>
  );
}
