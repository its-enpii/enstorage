'use client';

import { useEffect, useState } from 'react';
import { Cloud } from '@mui/icons-material';

export function ArchitectureFlowHero() {
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  return (
    <div className="relative mx-auto mt-10 w-full max-w-5xl overflow-hidden rounded-3xl border border-white/10 bg-linear-to-r from-[#0d1322] via-[#141226] to-[#1c0f2b] p-6 shadow-2xl sm:p-12">
      {/* SVG Canvas for Inbound & Outbound Glowing Beams */}
      <svg
        className="pointer-events-none absolute inset-0 size-full"
        viewBox="0 0 1000 450"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
        preserveAspectRatio="xMidYMid meet"
      >
        <defs>
          <linearGradient id="beamLeft" x1="0%" y1="50%" x2="100%" y2="50%">
            <stop offset="0%" stopColor="#38bdf8" stopOpacity="0.05" />
            <stop offset="60%" stopColor="#38bdf8" stopOpacity="0.3" />
            <stop offset="100%" stopColor="#818cf8" stopOpacity="0.75" />
          </linearGradient>
          <linearGradient id="beamRight" x1="0%" y1="50%" x2="100%" y2="50%">
            <stop offset="0%" stopColor="#a855f7" stopOpacity="0.8" />
            <stop offset="50%" stopColor="#c084fc" stopOpacity="0.4" />
            <stop offset="100%" stopColor="#e879f9" stopOpacity="0.05" />
          </linearGradient>
          <filter id="glow" x="-20%" y="-20%" width="140%" height="140%">
            <feGaussianBlur stdDeviation="5" result="blur" />
            <feComposite in="SourceGraphic" in2="blur" operator="over" />
          </filter>
        </defs>

        {/* Inbound Converging Lines (Left to Center) */}
        <path d="M 0 50 C 250 80, 400 200, 480 225" stroke="url(#beamLeft)" strokeWidth="1.5" />
        <path d="M 0 110 C 250 130, 400 210, 480 225" stroke="url(#beamLeft)" strokeWidth="1.5" />
        <path d="M 0 170 C 250 180, 400 220, 480 225" stroke="url(#beamLeft)" strokeWidth="2" />
        <path d="M 0 225 L 480 225" stroke="url(#beamLeft)" strokeWidth="2.5" />
        <path d="M 0 280 C 250 270, 400 230, 480 225" stroke="url(#beamLeft)" strokeWidth="2" />
        <path d="M 0 340 C 250 320, 400 240, 480 225" stroke="url(#beamLeft)" strokeWidth="1.5" />
        <path d="M 0 400 C 250 370, 400 250, 480 225" stroke="url(#beamLeft)" strokeWidth="1.5" />

        {/* Animated Data Pulse on Central Left Beam */}
        <circle cx="240" cy="225" r="4" fill="#38bdf8" filter="url(#glow)">
          <animate attributeName="cx" values="0;480" dur="2.8s" repeatCount="indefinite" />
          <animate attributeName="opacity" values="0;1;0" dur="2.8s" repeatCount="indefinite" />
        </circle>

        {/* Outbound Distribution Lines (Center to Right) */}
        <path d="M 520 225 L 1000 225" stroke="url(#beamRight)" strokeWidth="2" />
        <path d="M 520 225 C 600 225, 620 120, 720 120 L 1000 120" stroke="url(#beamRight)" strokeWidth="1.5" />
        <path d="M 520 225 C 600 225, 620 330, 720 330 L 1000 330" stroke="url(#beamRight)" strokeWidth="1.5" />

        {/* Outbound Animated Data Pulses */}
        <circle cx="520" cy="225" r="4" fill="#c084fc" filter="url(#glow)">
          <animate attributeName="cx" values="520;1000" dur="3s" repeatCount="indefinite" />
          <animate attributeName="opacity" values="1;1;0" dur="3s" repeatCount="indefinite" />
        </circle>
        <circle cx="720" cy="120" r="3.5" fill="#e879f9" filter="url(#glow)">
          <animate attributeName="cx" values="720;1000" dur="3.5s" repeatCount="indefinite" />
          <animate attributeName="opacity" values="1;1;0" dur="3.5s" repeatCount="indefinite" />
        </circle>

        {/* Circuit Nodes on Right Beam */}
        <circle cx="780" cy="225" r="4" fill="#a855f7" filter="url(#glow)" />
        <circle cx="900" cy="225" r="4" fill="#c084fc" filter="url(#glow)" />
      </svg>

      {/* Center Hero Floating Squircle Card */}
      <div className="relative z-10 flex min-h-[320px] items-center justify-center">
        {/* Floating Circuit Sub-Nodes (Top Right & Bottom Left) */}
        <div className="absolute top-8 right-[26%] hidden size-11 items-center justify-center rounded-xl border border-purple-500/30 bg-[#23153c]/80 shadow-[0_0_15px_rgba(168,85,247,0.25)] backdrop-blur-md sm:flex">
          <span className="size-3.5 rounded-sm bg-purple-400 shadow-[0_0_8px_rgba(192,132,252,0.8)]" />
        </div>
        <div className="absolute bottom-8 left-[30%] hidden size-11 items-center justify-center rounded-xl border border-indigo-500/30 bg-[#161738]/80 shadow-[0_0_15px_rgba(99,102,241,0.25)] backdrop-blur-md sm:flex">
          <div className="grid grid-cols-2 gap-1">
            <span className="size-1.5 rounded-xs bg-indigo-400" />
            <span className="size-1.5 rounded-xs bg-indigo-400" />
            <span className="size-1.5 rounded-xs bg-indigo-400" />
            <span className="size-1.5 rounded-xs bg-indigo-400" />
          </div>
        </div>

        {/* The EnStorage Central Emblem */}
        <div className="group relative flex size-32 items-center justify-center rounded-3xl border border-white/15 bg-linear-to-b from-[#2a244d] to-[#17142d] p-1 shadow-[0_0_50px_rgba(129,140,248,0.3)] transition-transform duration-300 hover:scale-105 sm:size-36">
          <div className="absolute -inset-1 rounded-3xl bg-linear-to-r from-blue-500/30 via-indigo-500/40 to-purple-500/30 blur-md" />
          <div className="relative flex size-full items-center justify-center rounded-[22px] bg-[#1a1733] shadow-inner">
            {/* Custom Multi-layered Icon (Glowing Triangle Wings + Golden Bolt) */}
            <div className="relative flex items-center justify-center">
              {/* Outer Cyan/Purple Wing Badge */}
              <svg className="size-16" viewBox="0 0 64 64" fill="none">
                <path
                  d="M 12 18 L 32 50 L 52 18 Z"
                  fill="url(#wingGradient)"
                  filter="url(#glow)"
                />
                <defs>
                  <linearGradient id="wingGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                    <stop offset="0%" stopColor="#38bdf8" />
                    <stop offset="50%" stopColor="#818cf8" />
                    <stop offset="100%" stopColor="#a855f7" />
                  </linearGradient>
                </defs>
              </svg>
              {/* Central Golden Lightning Bolt */}
              <svg
                className="absolute size-9 text-amber-400 drop-shadow-[0_0_10px_rgba(251,191,36,0.9)]"
                viewBox="0 0 24 24"
                fill="currentColor"
              >
                <path d="M11 21h-1l1-7H7.5c-.88 0-.33-.75-.31-.78C8.48 10.94 10.42 7.54 13.01 3h1l-1 7h3.5c.49 0 .73.3.43.76L11 21z" />
              </svg>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
