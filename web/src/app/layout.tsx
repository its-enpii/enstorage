import type { Metadata } from 'next';
import localFont from 'next/font/local';
import { AuthProvider } from '@/components/AuthProvider';
import { PromptProvider } from '@/components/usePrompt';
import { ThemeProvider } from '@/components/ThemeProvider';
import { I18nProvider } from '@/components/I18nProvider';
import { RealtimeProvider } from '@/lib/realtime/realtimeProvider';
import './globals.css';

const inter = localFont({
  src: '../../public/fonts/inter-latin.woff2',
  variable: '--font-inter',
  display: 'swap',
  weight: '100 900',
});

const dmSans = localFont({
  src: '../../public/fonts/dm-sans-latin.woff2',
  variable: '--font-dm-sans',
  display: 'swap',
  weight: '100 1000',
});

export const metadata: Metadata = {
  title: 'EnStorage',
  description: 'Never Leaves Your Drive.',
  icons: {
    icon: [
      { url: '/icon-192.png', sizes: '192x192', type: 'image/png' },
      { url: '/icon-512.png', sizes: '512x512', type: 'image/png' },
    ],
    apple: [{ url: '/apple-touch-icon.png', sizes: '180x180', type: 'image/png' }],
  },
  manifest: '/manifest.webmanifest',
  robots: {
    index: false,
    follow: false,
    noarchive: true,
    nosnippet: true,
    noimageindex: true,
    googleBot: { index: false, follow: false },
  },
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
        <link rel="icon" href="/favicon.ico" sizes="any" />
        <link rel="apple-touch-icon" href="/apple-touch-icon.png" />
      </head>
      <body className={`${inter.variable} ${dmSans.variable}`}>
        <ThemeProvider>
          <I18nProvider>
            <AuthProvider>
              <RealtimeProvider>
                <PromptProvider>{children}</PromptProvider>
              </RealtimeProvider>
            </AuthProvider>
          </I18nProvider>
        </ThemeProvider>
      </body>
    </html>
  );
}