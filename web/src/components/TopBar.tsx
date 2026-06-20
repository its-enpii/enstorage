'use client';

import { useState } from 'react';
import { DarkMode, LightMode, Menu, Search as SearchIcon } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import { useTheme } from '@/components/ThemeProvider';
import { SearchInput } from '@/components/SearchInput';
import { CommandPalette } from '@/components/CommandPalette';

type Props = {
  search?: string;
  onSearchChange?: (v: string) => void;
  searchPlaceholder?: string;
  sidebarOpen?: boolean;
  onToggleSidebar?: () => void;
};

export function TopBar({
  search,
  onSearchChange,
  searchPlaceholder,
  sidebarOpen,
  onToggleSidebar,
}: Props) {
  const { t } = useTranslation();
  const { resolved, setTheme, theme } = useTheme();
  const [paletteOpen, setPaletteOpen] = useState(false);

  function toggleTheme() {
    if (theme === 'system') {
      setTheme(resolved === 'dark' ? 'light' : 'dark');
    } else {
      setTheme(resolved === 'dark' ? 'light' : 'dark');
    }
  }

  function openPalette() {
    setPaletteOpen(true);
  }

  return (
    <>
      <header className="h-16 sm:h-20 px-4 sm:px-container-padding flex items-center gap-3 sm:gap-4 z-40 shrink-0">
        {/* Hamburger — mobile only, opens sidebar drawer */}
        <button
          type="button"
          onClick={onToggleSidebar}
          aria-label="Open menu"
          className="sm:hidden w-10 h-10 shrink-0 flex items-center justify-center rounded-xl bg-surface-container text-on-surface hover:bg-surface-container-high transition-colors"
        >
          <Menu className="!text-xl" />
        </button>

        {/* Search — mobile: icon button, desktop: full text input */}
        <div className="flex-1 max-w-2xl mx-auto">
          {/* Mobile icon */}
          <button
            type="button"
            onClick={openPalette}
            aria-label={t('search.placeholder')}
            className="sm:hidden w-10 h-10 mx-auto flex items-center justify-center rounded-xl bg-surface-container text-on-surface hover:bg-surface-container-high transition-colors"
          >
            <SearchIcon className="!text-xl" />
          </button>
          {/* Desktop text input */}
          <button
            onClick={openPalette}
            className="hidden sm:block w-full text-left"
            aria-label={t('search.placeholder')}
          >
            <SearchInput
              readOnly
              value=""
              onChange={() => {}}
              placeholder={searchPlaceholder ?? t('search.placeholder')}
              className="cursor-pointer"
            />
          </button>
        </div>

        <div className="flex items-center gap-3 sm:gap-4">
          <button
            onClick={toggleTheme}
            title={resolved === 'dark' ? t('settings.themeTerang') : t('settings.themeGelap')}
            className="w-10 h-10 flex items-center justify-center rounded-xl bg-surface-container text-on-surface hover:bg-surface-container-high transition-colors"
          >
            {resolved === 'dark' ? <LightMode className="!text-xl" /> : <DarkMode className="!text-xl" />}
          </button>
        </div>
      </header>
      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
    </>
  );
}
