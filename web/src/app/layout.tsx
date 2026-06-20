import type { Metadata } from 'next';
import { DM_Sans, Inter } from 'next/font/google';
import { AuthProvider } from '@/components/AuthProvider';
import { PromptProvider } from '@/components/usePrompt';
import { ThemeProvider } from '@/components/ThemeProvider';
import { I18nProvider } from '@/components/I18nProvider';
import './globals.css';

const inter = Inter({
  subsets: ['latin'],
  variable: '--font-inter',
  display: 'swap',
});

const dmSans = DM_Sans({
  subsets: ['latin'],
  weight: ['400', '600', '700'],
  variable: '--font-dm-sans',
  display: 'swap',
});

export const metadata: Metadata = {
  title: 'EnStorage',
  description: 'Self-hosted centralized file storage',
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="id" className="dark" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: `(function(){var t=localStorage.getItem('enstorage-theme')||'dark';var d=t==='system'?matchMedia('(prefers-color-scheme:dark)').matches:t==='dark';if(!d)document.documentElement.classList.remove('dark');document.documentElement.style.colorScheme=d?'dark':'light';var l=localStorage.getItem('enstorage_locale')||'id';document.documentElement.lang=l})()` }} />
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200&display=swap"
        />
      </head>
      <body className={`${inter.variable} ${dmSans.variable}`}>
        <ThemeProvider>
          <I18nProvider>
            <AuthProvider>
              <PromptProvider>{children}</PromptProvider>
            </AuthProvider>
          </I18nProvider>
        </ThemeProvider>
      </body>
    </html>
  );
}