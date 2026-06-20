'use client';

import { forwardRef, type ButtonHTMLAttributes, type ReactNode } from 'react';
import clsx from 'clsx';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'danger-soft';
type Size = 'sm' | 'md' | 'lg';

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant;
  size?: Size;
  fullWidth?: boolean;
  leftIcon?: ReactNode;
  rightIcon?: ReactNode;
  loading?: boolean;
};

const variantClass: Record<Variant, string> = {
  primary:
    'bg-primary-container text-on-primary-container hover:bg-primary-container/80 active:bg-primary-container/70',
  secondary:
    'border border-outline-variant/20 text-on-surface hover:bg-surface-container active:bg-surface-container-high',
  ghost:
    'text-on-surface hover:bg-surface-container active:bg-surface-container-high',
  danger:
    'bg-error-container text-on-error-container hover:bg-error-container/80',
  'danger-soft':
    'bg-error-container/15 text-error hover:bg-error-container/30',
};

const sizeClass: Record<Size, string> = {
  sm: 'h-9 px-3 text-label-sm rounded-lg',
  md: 'h-11 px-5 text-sm rounded-xl',
  lg: 'h-12 px-5 text-sm rounded-xl',
};

export const Button = forwardRef<HTMLButtonElement, Props>(function Button(
  {
    variant = 'primary',
    size = 'md',
    fullWidth,
    leftIcon,
    rightIcon,
    loading,
    disabled,
    children,
    className,
    ...rest
  },
  ref,
) {
  return (
    <button
      ref={ref}
      disabled={disabled || loading}
      className={clsx(
        'inline-flex items-center justify-center gap-2 font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed',
        variantClass[variant],
        sizeClass[size],
        fullWidth && 'w-full',
        className,
      )}
      {...rest}
    >
      {loading ? (
        <span className="w-4 h-4 rounded-full border-2 border-current border-t-transparent animate-spin" />
      ) : leftIcon}
      {children}
      {!loading && rightIcon}
    </button>
  );
});

type IconButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'default' | 'danger';
  active?: boolean;
};

const iconButtonVariant: Record<'default' | 'danger', string> = {
  default:
    'bg-surface-container text-on-surface hover:bg-primary/15 hover:text-primary',
  danger:
    'bg-surface-container text-error hover:bg-error/15',
};

const iconButtonActiveVariant: Record<'default' | 'danger', string> = {
  default: 'bg-secondary text-on-secondary',
  danger: 'bg-error text-on-error',
};

export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(function IconButton(
  { variant = 'default', active, className, children, ...rest },
  ref,
) {
  return (
    <button
      ref={ref}
      className={clsx(
        'w-7 h-7 rounded-lg flex items-center justify-center transition-colors disabled:opacity-50',
        active ? iconButtonActiveVariant[variant] : iconButtonVariant[variant],
        className,
      )}
      {...rest}
    >
      {children}
    </button>
  );
});

