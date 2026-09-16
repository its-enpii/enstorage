import clsx from 'clsx';

type Tone = 'primary' | 'secondary' | 'error';
type Size = 'xs' | 'sm' | 'md';

const toneClass: Record<Tone, string> = {
  primary: 'bg-primary',
  secondary: 'bg-secondary',
  error: 'bg-error',
};

const sizeClass: Record<Size, string> = {
  xs: 'h-1.5',
  sm: 'h-2',
  md: 'h-3',
};

type Props = {
  /** Fill percentage, 0–100. Values outside the range are clamped. */
  value: number;
  tone?: Tone;
  size?: Size;
  label?: string;
  className?: string;
  trackClassName?: string;
};

export function ProgressBar({
  value,
  tone = 'primary',
  size = 'sm',
  label,
  className,
  trackClassName,
}: Props) {
  const pct = Math.max(0, Math.min(100, Math.round(value)));
  return (
    <div
      role="progressbar"
      aria-valuenow={pct}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-label={label}
      className={clsx(
        'w-full overflow-hidden rounded-full bg-surface-container',
        sizeClass[size],
        trackClassName,
        className,
      )}
    >
      <div
        className={clsx('h-full rounded-full transition-all duration-300 ease-out', toneClass[tone])}
        style={{ width: `${pct}%` }}
      />
    </div>
  );
}
