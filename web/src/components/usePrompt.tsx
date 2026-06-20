'use client';

import { createContext, useCallback, useContext, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/Button';
import { Dialog } from '@/components/Dialog';
import { Input } from '@/components/Input';
import { CheckCircle, Error as ErrorIcon, HelpOutlined as HelpOutline } from '@mui/icons-material';

type AlertOpts = { title?: string; description?: string };
type ConfirmOpts = { title?: string; description?: string; danger?: boolean; confirmLabel?: string; cancelLabel?: string };

type PromptContextValue = {
  alert: (description: string, opts?: AlertOpts) => Promise<void>;
  confirm: (description: string, opts?: ConfirmOpts) => Promise<boolean>;
  prompt: (description: string, opts?: AlertOpts & { placeholder?: string; defaultValue?: string }) => Promise<string | null>;
};

const Ctx = createContext<PromptContextValue | null>(null);

export function PromptProvider({ children }: { children: ReactNode }) {
  const { t } = useTranslation();
  const [alertState, setAlertState] = useState<{ open: boolean; opts: AlertOpts & { description: string } }>({
    open: false,
    opts: { description: '' },
  });
  const [alertResolve, setAlertResolve] = useState<(() => void) | null>(null);

  const [confirmState, setConfirmState] = useState<{
    open: boolean;
    opts: ConfirmOpts & { description: string };
  }>({ open: false, opts: { description: '' } });
  const [confirmResolve, setConfirmResolve] = useState<((v: boolean) => void) | null>(null);

  const [promptState, setPromptState] = useState<{
    open: boolean;
    opts: AlertOpts & { description: string; placeholder?: string; defaultValue?: string };
  }>({ open: false, opts: { description: '' } });
  const [promptValue, setPromptValue] = useState('');
  const [promptResolve, setPromptResolve] = useState<((v: string | null) => void) | null>(null);

  const alert = useCallback((description: string, opts: AlertOpts = {}) => {
    return new Promise<void>((resolve) => {
      setAlertState({ open: true, opts: { ...opts, description } });
      setAlertResolve(() => resolve);
    });
  }, []);

  const confirm = useCallback((description: string, opts: ConfirmOpts = {}) => {
    return new Promise<boolean>((resolve) => {
      setConfirmState({ open: true, opts: { ...opts, description } });
      setConfirmResolve(() => resolve);
    });
  }, []);

  const prompt = useCallback(
    (description: string, opts: AlertOpts & { placeholder?: string; defaultValue?: string } = {}) => {
      return new Promise<string | null>((resolve) => {
        setPromptValue(opts.defaultValue ?? '');
        setPromptState({ open: true, opts: { ...opts, description } });
        setPromptResolve(() => resolve);
      });
    },
    [],
  );

  function closeAlert() {
    setAlertState((s) => ({ ...s, open: false }));
    alertResolve?.();
    setAlertResolve(null);
  }
  function closeConfirm(result: boolean) {
    setConfirmState((s) => ({ ...s, open: false }));
    confirmResolve?.(result);
    setConfirmResolve(null);
  }
  function closePrompt(result: string | null) {
    setPromptState((s) => ({ ...s, open: false }));
    promptResolve?.(result);
    setPromptResolve(null);
  }

  return (
    <Ctx.Provider value={{ alert, confirm, prompt }}>
      {children}

      {/* Alert dialog */}
      <Dialog
        open={alertState.open}
        onClose={closeAlert}
        title={alertState.opts.title ?? t('common.info')}
        description={alertState.opts.description}
        icon={<ErrorIcon />}
        variant="default"
        actions={
          <Button onClick={closeAlert}>{t('common.ok')}</Button>
        }
      />

      {/* Confirm dialog */}
      <Dialog
        open={confirmState.open}
        onClose={() => closeConfirm(false)}
        title={confirmState.opts.title ?? t(confirmState.opts.danger ? 'common.confirmAction' : 'common.confirm')}
        description={confirmState.opts.description}
        icon={<HelpOutline />}
        variant={confirmState.opts.danger ? 'danger' : 'default'}
        actions={
          <>
            <Button variant="secondary" onClick={() => closeConfirm(false)}>
              {confirmState.opts.cancelLabel ?? t('common.cancel')}
            </Button>
            <Button
              variant={confirmState.opts.danger ? 'danger' : 'primary'}
              onClick={() => closeConfirm(true)}
            >
              {confirmState.opts.confirmLabel ?? t('common.confirm')}
            </Button>
          </>
        }
      />

      {/* Prompt dialog */}
      <Dialog
        open={promptState.open}
        onClose={() => closePrompt(null)}
        title={promptState.opts.title ?? t('common.input')}
        description={promptState.opts.description}
        icon={<CheckCircle />}
        actions={
          <>
            <Button variant="secondary" onClick={() => closePrompt(null)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={() => closePrompt(promptValue)}>{t('common.ok')}</Button>
          </>
        }
      >
        <Input
          autoFocus
          value={promptValue}
          onChange={(e) => setPromptValue(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') closePrompt(promptValue);
          }}
          placeholder={promptState.opts.placeholder}
        />
      </Dialog>
    </Ctx.Provider>
  );
}

export function usePrompt(): PromptContextValue {
  const c = useContext(Ctx);
  if (!c) throw new Error('usePrompt must be used inside PromptProvider');
  return c;
}
