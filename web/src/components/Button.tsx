'use client';

import { forwardRef, type ReactNode } from 'react';
import type {
  AnchorHTMLAttributes,
  ButtonHTMLAttributes,
} from 'react';
import clsx from 'clsx';
import { Spinner } from '@/components/Spinner';

export type ButtonVariant =
  | 'primary'
  | 'secondary'
  | 'tonal'
  | 'ghost'
  | 'danger'
  | 'danger-soft'
  | 'link';

export type ButtonSize = 'sm' | 'toolbar' | 'md' | 'lg' | 'pill';

export const BUTTON_BASE =
  'inline-flex items-center justify-center gap-2 font-semibold transition-colors disabled:opacity-50 disabled:cursor-not-allowed';

export const buttonVariantClass: Record<ButtonVariant, string> = {
  primary:
    'bg-primary-container text-on-primary-container hover:bg-primary-container/80 active:bg-primary-container/70',
  secondary:
    'border border-outline-variant/20 text-on-surface hover:bg-surface-container active:bg-surface-container-high',
  tonal:
    'bg-surface-container text-on-surface hover:bg-surface-container-high active:bg-surface-container-highest',
  ghost:
    'text-on-surface hover:bg-surface-container active:bg-surface-container-high',
  danger:
    'bg-error-container text-on-error-container hover:bg-error-container/80',
  'danger-soft':
    'bg-error-container/15 text-error hover:bg-error-container/30',
  link: 'text-primary underline-offset-2 hover:underline',
};

export const buttonSizeClass: Record<ButtonSize, string> = {
  sm: 'h-9 px-3 text-label-sm rounded-lg',
  toolbar: 'h-10 px-4 text-sm rounded-xl',
  md: 'h-11 px-5 text-sm rounded-xl',
  lg: 'h-12 px-5 text-sm rounded-xl',
  pill: 'px-5 py-2.5 text-sm rounded-full',
};

/** Shared class builder so anchors can be styled identically to buttons. */
export function buttonClasses(
  variant: ButtonVariant = 'primary',
  size: ButtonSize = 'md',
  className?: string,
): string {
  return clsx(
    BUTTON_BASE,
    variant !== 'link' && buttonSizeClass[size],
    buttonVariantClass[variant],
    className,
  );
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant;
  size?: ButtonSize;
  fullWidth?: boolean;
  leftIcon?: ReactNode;
  rightIcon?: ReactNode;
  loading?: boolean;
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button(
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
        buttonClasses(variant, size, className),
        fullWidth && 'w-full',
      )}
      {...rest}
    >
      {loading ? <Spinner size="sm" /> : leftIcon}
      {children}
      {!loading && rightIcon}
    </button>
  );
});

/** Quiet text-only action used inline in lists, sheets and selection bars. */
export function TextAction({
  children,
  className,
  ...rest
}: ButtonProps) {
  return (
    <Button
      variant="link"
      className={clsx('text-sm font-medium text-outline hover:text-on-surface', className)}
      {...rest}
    >
      {children}
    </Button>
  );
}

type LinkButtonProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'download'> & {
  download?: boolean | string;
  variant?: ButtonVariant;
  size?: ButtonSize;
  fullWidth?: boolean;
  leftIcon?: ReactNode;
  rightIcon?: ReactNode;
};

/** Anchor styled as a Button — for navigation and file downloads. */
export const LinkButton = forwardRef<HTMLAnchorElement, LinkButtonProps>(
  function LinkButton(
    {
      variant = 'primary',
      size = 'md',
      fullWidth,
      leftIcon,
      rightIcon,
      children,
      className,
      ...rest
    },
    ref,
  ) {
    return (
      <a
        ref={ref}
        className={clsx(
          buttonClasses(variant, size, className),
          fullWidth && 'w-full',
        )}
        {...rest}
      >
        {leftIcon}
        {children}
        {rightIcon}
      </a>
    );
  },
);

/** Anchor twin of `IconButton` — icon-only links (downloads, external targets). */
export const IconLink = forwardRef<HTMLAnchorElement, IconLinkProps>(function IconLink(
  { variant = 'default', size = 'sm', shape = 'rounded', bare, className, children, ...rest },
  ref,
) {
  return (
    <a
      ref={ref}
      className={clsx(
        'flex items-center justify-center shrink-0 transition-colors',
        iconButtonSizeClass[size],
        iconButtonShapeClass[shape],
        bare ? iconButtonBareVariant[variant] : iconButtonVariant[variant],
        className,
      )}
      {...rest}
    >
      {children}
    </a>
  );
});

type IconLinkProps = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'download'> & {
  download?: boolean | string;
  variant?: 'default' | 'danger';
  size?: 'sm' | 'md' | 'lg' | 'xl';
  shape?: 'rounded' | 'circle';
  bare?: boolean;
};

type IconButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'default' | 'danger';
  active?: boolean;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  shape?: 'rounded' | 'circle';
  /** No fill — transparent until hovered. */
  bare?: boolean;
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

const iconButtonBareVariant: Record<'default' | 'danger', string> = {
  default: 'text-outline hover:bg-surface-container hover:text-on-surface',
  danger: 'text-error hover:bg-error/15',
};

const iconButtonSizeClass: Record<NonNullable<IconButtonProps['size']>, string> = {
  sm: 'w-7 h-7',
  md: 'w-9 h-9',
  lg: 'w-10 h-10',
  xl: 'w-11 h-11',
};

const iconButtonShapeClass: Record<NonNullable<IconButtonProps['shape']>, string> = {
  rounded: 'rounded-lg',
  circle: 'rounded-full',
};

export const IconButton = forwardRef<HTMLButtonElement, IconButtonProps>(
  function IconButton(
    {
      variant = 'default',
      active,
      size = 'sm',
      shape = 'rounded',
      bare,
      className,
      children,
      ...rest
    },
    ref,
  ) {
    return (
      <button
        ref={ref}
        className={clsx(
          'flex items-center justify-center shrink-0 transition-colors disabled:opacity-50 disabled:cursor-not-allowed',
          iconButtonSizeClass[size],
          iconButtonShapeClass[shape],
          bare
            ? iconButtonBareVariant[variant]
            : active
              ? iconButtonActiveVariant[variant]
              : iconButtonVariant[variant],
          className,
        )}
        {...rest}
      >
        {children}
      </button>
    );
  },
);
