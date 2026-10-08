'use client';

import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/Modal';
import { Button } from '@/components/Button';
import { CloseIcon } from '@/lib/icons';

type Props = {
  open: boolean;
  /** Embeddable Google Picker URI from `openGooglePicker`'s `onUri`. */
  uri: string;
  /** Fired on the close button, backdrop or ESC — before any selection. */
  onClose: () => void;
};

/**
 * Wraps the embedded Google Picker iframe in the app's own modal chrome so the
 * picker sits inside a frame we control (radius, surface, border, header)
 * instead of Google's default dialog. Only the frame is styled here — nothing
 * inside the iframe is touched.
 */
export function GooglePickerDialog({ open, uri, onClose }: Props) {
  const { t } = useTranslation();

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      panelClassName="h-[78vh] flex flex-col p-0 overflow-hidden"
    >
      <div className="flex items-center justify-between px-inner-padding py-3 border-b border-outline-variant/20">
        <h2 className="font-display text-title-lg text-on-surface truncate">
          {t('accounts.import.pickerTitle')}
        </h2>
        <Button
          variant="ghost"
          size="sm"
          onClick={onClose}
          aria-label={t('common.cancel')}
        >
          <CloseIcon />
        </Button>
      </div>
      <iframe
        src={uri}
        title={t('accounts.import.pickerTitle')}
        allow="clipboard-write"
        className="w-full flex-1 border-none bg-surface"
      />
    </Modal>
  );
}
