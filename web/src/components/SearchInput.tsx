'use client';

import { forwardRef, type InputHTMLAttributes } from 'react';
import { Search } from '@mui/icons-material';
import clsx from 'clsx';

type Props = InputHTMLAttributes<HTMLInputElement>;

export const SearchInput = forwardRef<HTMLInputElement, Props>(function SearchInput(
  { className, ...rest },
  ref,
) {
  return (
    <div className="relative w-full group">
      <Search className="absolute left-4 top-1/2 -translate-y-1/2 text-outline !text-xl group-focus-within:text-primary transition-colors pointer-events-none" />
      <input
        ref={ref}
        type="text"
        className={clsx(
          'block w-full bg-surface-container h-12 rounded-xl pl-12 pr-4 border-none text-on-surface placeholder:text-outline focus:ring-2 focus:ring-primary/20 focus:outline-none focus:bg-surface-container-high transition-all text-sm',
          className,
        )}
        {...rest}
      />
    </div>
  );
});
