'use client';

import { forwardRef, type InputHTMLAttributes, type TextareaHTMLAttributes, type ReactNode, type SelectHTMLAttributes } from 'react';
import clsx from 'clsx';
import { KeyboardArrowDown } from '@mui/icons-material';

const FIELD_CLASSES =
  'block w-full h-12 rounded-xl bg-background px-4 border-none text-on-surface placeholder:text-outline focus:ring-2 focus:ring-primary/20 focus:outline-none transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed';

const BARE_FIELD_CLASSES =
  'block w-full bg-transparent text-on-surface placeholder:text-outline focus:outline-none';

type InputProps = InputHTMLAttributes<HTMLInputElement> & {
  invalid?: boolean;
  /** `filled` gets the standard container field; `bare` is transparent for custom shells. */
  variant?: 'filled' | 'bare';
  leftIcon?: ReactNode;
  rightIcon?: ReactNode;
  /** Class for the positioning wrapper. */
  wrapperClassName?: string;
};

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { className, invalid, variant = 'filled', leftIcon, rightIcon, wrapperClassName, ...rest },
  ref,
) {
  const basePadding = leftIcon ? 'pl-12' : rightIcon ? 'pr-12' : '';
  return (
    <div className={clsx('relative w-full', wrapperClassName)}>
      {leftIcon && (
        <span className="absolute left-4 top-1/2 -translate-y-1/2 text-outline pointer-events-none">
          {leftIcon}
        </span>
      )}
      <input
        ref={ref}
        className={clsx(
          variant === 'bare' ? BARE_FIELD_CLASSES : FIELD_CLASSES,
          basePadding,
          invalid && 'ring-2 ring-error',
          className,
        )}
        {...rest}
      />
      {rightIcon && (
        <span className="absolute right-4 top-1/2 -translate-y-1/2 text-outline pointer-events-none">
          {rightIcon}
        </span>
      )}
    </div>
  );
});

type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement> & { invalid?: boolean };
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaProps>(function Textarea(
  { className, invalid, ...rest },
  ref,
) {
  return (
    <textarea
      ref={ref}
      className={clsx(
        'block w-full min-h-24 rounded-xl bg-background p-4 border-none text-on-surface placeholder:text-outline focus:ring-2 focus:ring-primary/20 focus:outline-none transition-all text-sm resize-y',
        invalid && 'ring-2 ring-error',
        className,
      )}
      {...rest}
    />
  );
});

type SelectProps = SelectHTMLAttributes<HTMLSelectElement> & { invalid?: boolean };
export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
  { className, invalid, children, ...rest },
  ref,
) {
  return (
    <div className="relative w-full">
      <select
        ref={ref}
        className={clsx(
          'block w-full h-12 rounded-xl bg-background px-4 pr-12 border-none text-on-surface focus:ring-2 focus:ring-primary/20 focus:outline-none transition-all text-sm appearance-none cursor-pointer disabled:opacity-50',
          invalid && 'ring-2 ring-error',
          className,
        )}
        {...rest}
      >
        {children}
      </select>
      <KeyboardArrowDown
        aria-hidden
        className="!text-xl text-outline absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none"
      />
    </div>
  );
});

type FieldProps = {
  label: string;
  htmlFor?: string;
  hint?: string;
  error?: string | null;
  children: ReactNode;
  className?: string;
};

export function Field({ label, htmlFor, hint, error, children, className }: FieldProps) {
  return (
    <div className={className}>
      <label
        htmlFor={htmlFor}
        className="block text-label-sm uppercase text-on-surface-variant mb-2"
      >
        {label}
      </label>
      {children}
      {error ? (
        <p className="mt-1.5 text-metadata text-error">{error}</p>
      ) : hint ? (
        <p className="mt-1.5 text-metadata text-outline">{hint}</p>
      ) : null}
    </div>
  );
}
