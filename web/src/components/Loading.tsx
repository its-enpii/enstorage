import clsx from 'clsx';

type Props = {
  label?: string;
  fullscreen?: boolean;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
};

const sizeStyles = {
  sm: 'w-6 h-6 border-2',
  md: 'w-10 h-10 border-[3px]',
  lg: 'w-16 h-16 border-4',
};

export function Loading({ label, fullscreen, size = 'md', className }: Props) {
  return (
    <div
      className={clsx(
        'flex flex-col items-center justify-center gap-4',
        fullscreen ? 'min-h-screen' : 'py-16',
        className,
      )}
    >
      <div
        className={clsx(
          sizeStyles[size],
          'rounded-full border-outline-variant border-t-primary animate-spin',
        )}
        role="status"
        aria-label={label ?? 'Loading'}
      />
      {label && (
        <p className="text-metadata text-on-surface-variant uppercase tracking-wider">
          {label}
        </p>
      )}
    </div>
  );
}
