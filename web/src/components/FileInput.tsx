'use client';

import {
  forwardRef,
  useImperativeHandle,
  useRef,
  type ChangeEvent,
} from 'react';

export type FileInputHandle = {
  /** Open the OS file picker. */
  open: () => void;
};

type Props = {
  onSelect: (files: FileList) => void;
  multiple?: boolean;
  /** Allow picking a whole folder (webkit directory upload). */
  directory?: boolean;
  accept?: string;
  className?: string;
};

/**
 * Hidden `<input type="file">` with an imperative `open()` handle, so any
 * Button/IconButton can trigger a picker without re-declaring raw inputs.
 */
export const FileInput = forwardRef<FileInputHandle, Props>(function FileInput(
  { onSelect, multiple, directory, accept, className },
  ref,
) {
  const inputRef = useRef<HTMLInputElement>(null);

  useImperativeHandle(ref, () => ({
    open: () => inputRef.current?.click(),
  }));

  function handleChange(event: ChangeEvent<HTMLInputElement>) {
    const files = event.target.files;
    if (files?.length) onSelect(files);
    // Allow re-selecting the same file/folder.
    event.target.value = '';
  }

  return (
    <input
      ref={inputRef}
      type="file"
      hidden
      multiple={multiple}
      accept={accept}
      className={className}
      // @ts-expect-error webkitdirectory is not in the React type definitions
      webkitdirectory={directory ? '' : undefined}
      directory={directory ? '' : undefined}
      onChange={handleChange}
    />
  );
});
