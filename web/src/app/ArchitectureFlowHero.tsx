import { useState } from 'react';
import {
  AccountTree,
  Cloud,
  Code,
  FolderOpen,
  Person,
  Search,
  Storage,
} from '@mui/icons-material';
import clsx from 'clsx';

export function ArchitectureFlowHero() {
  const [activeTab, setActiveTab] = useState<'upload' | 'read' | 'stream'>('upload');

  return (
    <div className="relative mx-auto mt-12 w-full max-w-4xl rounded-2xl border border-outline-variant/30 bg-surface-container/50 p-6 backdrop-blur-sm sm:p-10">
      {/* Visual Flow Diagram */}
      <div className="grid grid-cols-1 items-center gap-8 md:grid-cols-3 md:gap-6">
        {/* Sisi Kiri: User / Client */}
        <div className="flex flex-col items-center justify-center space-y-3 rounded-xl border border-outline-variant/20 bg-surface p-6 text-center shadow-xs">
          <div className="flex size-12 items-center justify-center rounded-xl bg-primary-container text-on-primary-container">
            <Person className="!text-3xl" />
          </div>
          <div>
            <h4 className="font-display text-body-md font-bold text-on-surface">User & Client</h4>
            <p className="mt-1 text-xs text-on-surface-variant">Web App, Scripts, REST API</p>
          </div>
        </div>

        {/* Tengah: EnStorage Hub */}
        <div className="relative flex flex-col items-center justify-center space-y-3 rounded-xl border-2 border-primary/40 bg-surface p-6 text-center shadow-md">
          {/* Subtle Glow */}
          <div className="absolute -inset-0.5 rounded-xl bg-primary/10 blur-sm" />
          <div className="relative flex size-14 items-center justify-center rounded-2xl bg-primary text-on-primary shadow-sm">
            <Cloud className="!text-3xl" />
          </div>
          <div className="relative">
            <h4 className="font-display text-body-lg font-bold text-on-surface">EnStorage</h4>
            <p className="mt-1 text-xs font-semibold text-primary">Smart Router & Hub</p>
          </div>
        </div>

        {/* Sisi Kanan: Akun-akun Google Drive */}
        <div className="flex flex-col space-y-2.5">
          {[
            { name: 'Google Drive A', desc: 'Akun Utama (15 GB)', active: true },
            { name: 'Google Drive B', desc: 'Akun Kerja (15 GB)', active: false },
            { name: 'Google Drive C', desc: 'Akun Cadangan (15 GB)', active: false },
          ].map((drive, idx) => (
            <div
              key={idx}
              className={clsx(
                'flex items-center gap-3 rounded-lg border px-4 py-3 transition-colors',
                drive.active
                  ? 'border-primary/50 bg-primary-container/20 text-on-surface'
                  : 'border-outline-variant/20 bg-surface text-on-surface-variant',
              )}
            >
              <Storage className={clsx('!text-xl', drive.active ? 'text-primary' : 'text-outline')} />
              <div className="min-w-0 flex-1 text-left">
                <div className="text-xs font-bold text-on-surface">{drive.name}</div>
                <div className="text-[11px] text-on-surface-variant">{drive.desc}</div>
              </div>
              {drive.active && (
                <span className="rounded bg-primary/20 px-1.5 py-0.5 text-[10px] font-bold text-primary">
                  Target
                </span>
              )}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
