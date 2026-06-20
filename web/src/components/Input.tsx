'use client';

import { forwardRef, type InputHTMLAttributes, type TextareaHTMLAttributes, type ReactNode, type SelectHTMLAttributes } from 'react';
import clsx from 'clsx';

const FIELD_CLASSES =
  'block w-full h-12 rounded-xl bg-background px-4 border-none text-on-surface placeholder:text-outline focus:ring-2 focus:ring-primary/20 focus:outline-none transition-all text-sm disabled:opacity-50 disabled:cursor-not-allowed';

type InputProps = InputHTMLAttributes<HTMLInputElement> & {
  invalid?: boolean;
  leftIcon?: ReactNode;
  rightIcon?: ReactNode;
};

export const Input = forwardRef<HTMLInputElement, InputProps>(function Input(
  { className, invalid, leftIcon, rightIcon, ...rest },
  ref,
) {
  const basePadding = leftIcon ? 'pl-12' : rightIcon ? 'pr-12' : '';
  return (
    <div className="relative w-full">
      {leftIcon && (
        <span className="absolute left-4 top-1/2 -translate-y-1/2 text-outline pointer-events-none">
          {leftIcon}
        </span>
      )}
      <input
        ref={ref}
        className={clsx(
          FIELD_CLASSES,
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
    <select
      ref={ref}
      className={clsx(
        'block w-full h-12 rounded-xl bg-background px-4 border-none text-on-surface focus:ring-2 focus:ring-primary/20 focus:outline-none transition-all text-sm appearance-none cursor-pointer disabled:opacity-50',
        'bg-[url("data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22%23928f9d%22%3E%3Cpath%20d%3D%22M7%2010l5%205%205-5z%22%2F%3E%3C%2Fsvg%3E")] bg-no-repeat bg-right pr-12',
        invalid && 'ring-2 ring-error',
        className,
      )}
      {...rest}
    >
      {children}
    </select>
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
