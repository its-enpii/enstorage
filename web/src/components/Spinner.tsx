import clsx from 'clsx';

type Size = 'xs' | 'sm' | 'md' | 'lg';

const sizeClass: Record<Size, string> = {
  xs: 'w-3.5 h-3.5 border-2',
  sm: 'w-4 h-4 border-2',
  md: 'w-10 h-10 border-[3px]',
  lg: 'w-16 h-16 border-4',
};

type Props = {
  size?: Size;
  className?: string;
  label?: string;
};

/** Bare spinning ring — inherits the current text color. */
export function Spinner({ size = 'md', className, label }: Props) {
  return (
    <span
      role="status"
      aria-label={label ?? 'Loading'}
      className={clsx(
        'inline-block shrink-0 rounded-full border-current border-t-transparent animate-spin',
        sizeClass[size],
        className,
      )}
    />
  );
}
