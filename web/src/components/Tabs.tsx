import clsx from 'clsx';

type Tab = { value: string; label: string; count?: number };

type Props = {
  tabs: Tab[];
  value: string;
  onChange: (v: string) => void;
  className?: string;
};

export function Tabs({ tabs, value, onChange, className }: Props) {
  return (
    <div className={clsx('inline-flex items-end gap-1', className)}>
      {tabs.map((t) => {
        const active = value === t.value;
        return (
          <button
            key={t.value}
            onClick={() => onChange(t.value)}
            className={clsx(
              'group relative px-3 py-2 text-sm font-medium transition-colors',
              active
                ? 'text-on-surface'
                : 'text-on-surface-variant hover:text-on-surface',
            )}
          >
            <span className="flex items-center gap-1.5">
              {t.label}
              {typeof t.count === 'number' && (
                <span
                  className={clsx(
                    'text-metadata tabular-nums',
                    active ? 'text-primary' : 'text-outline',
                  )}
                >
                  {t.count}
                </span>
              )}
            </span>
            <span
              className={clsx(
                'absolute inset-x-2 -bottom-px h-0.5 rounded-full transition-all',
                active
                  ? 'bg-primary opacity-100'
                  : 'bg-primary opacity-0 group-hover:opacity-30',
              )}
            />
          </button>
        );
      })}
    </div>
  );
}
