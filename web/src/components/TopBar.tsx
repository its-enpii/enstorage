'use client';

import { useState } from 'react';
import { DarkMode, LightMode, Menu, Search as SearchIcon } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';
import { Button, IconButton } from '@/components/Button';
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
        <IconButton
          type="button"
          onClick={onToggleSidebar}
          aria-label={t('nav.menu', 'Menu')}
          size="lg"
          className="sm:hidden"
        >
          <Menu className="!text-xl" />
        </IconButton>

        {/* Search — mobile: icon button, desktop: faux field that opens the palette */}
        <div className="flex-1 max-w-2xl mx-auto">
          {/* Mobile icon */}
          <IconButton
            type="button"
            onClick={openPalette}
            aria-label={t('search.placeholder')}
            size="lg"
            className="sm:hidden mx-auto"
          >
            <SearchIcon className="!text-xl" />
          </IconButton>
          {/* Desktop field */}
          <Button
            variant="secondary"
            size="lg"
            onClick={openPalette}
            aria-label={t('search.placeholder')}
            leftIcon={<SearchIcon className="!text-xl shrink-0" />}
            className="hidden sm:inline-flex w-full !justify-start !font-normal !bg-surface-container hover:!bg-surface-container-high text-outline"
          >
            <span className="truncate">{searchPlaceholder ?? t('search.placeholder')}</span>
          </Button>
        </div>

        <div className="flex items-center gap-3 sm:gap-4">
          <IconButton
            onClick={toggleTheme}
            title={resolved === 'dark' ? t('settings.themeTerang') : t('settings.themeGelap')}
            size="lg"
          >
            {resolved === 'dark' ? <LightMode className="!text-xl" /> : <DarkMode className="!text-xl" />}
          </IconButton>
        </div>
      </header>
      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
    </>
  );
}
