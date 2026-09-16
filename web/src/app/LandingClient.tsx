'use client';

import { useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import {
  AccountTree,
  ArrowForward,
  Code,
  Layers,
  Lock,
  PlayCircle,
  Route,
  Security,
  Shield,
  Terminal,
  VpnKey,
} from '@mui/icons-material';
import clsx from 'clsx';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/Button';
import { Card, CardIconBox } from '@/components/Card';
import { CodeBlock } from '@/components/CodeBlock';
import { PublicShell } from '@/components/PublicShell';
import { useGoogleSignIn } from '@/lib/useGoogleSignIn';
import { DOCS_HREF, prefersReducedMotion, scrollToId } from '@/lib/site';
import { ENDPOINT_GROUPS, SNIPPETS, TOTAL_ENDPOINTS, snippetCode } from '@/lib/apiCatalog';
import { usePageTitle } from '@/lib/usePageTitle';
import { ArchitectureFlowHero } from './ArchitectureFlowHero';

/** Landing anchors the shared header nav points at. */
const SECTIONS = [
  { id: 'features', labelKey: 'landing.nav.features' },
  { id: 'security', labelKey: 'landing.nav.security' },
  { id: 'api', labelKey: 'landing.nav.api' },
] as const;

/** Keeps anchor targets clear of the sticky header. */
const SCROLL_MARGIN = 'scroll-mt-24';

function useScrollSpy() {
  const [active, setActive] = useState<string>('');

  useEffect(() => {
    const visible = new Map<string, number>();
    const observer = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          const id = (entry.target as HTMLElement).id;
          if (entry.isIntersecting) visible.set(id, entry.intersectionRatio);
          else visible.delete(id);
        }
        let best = '';
        let bestRatio = 0;
        visible.forEach((ratio, id) => {
          if (ratio >= bestRatio) {
            bestRatio = ratio;
            best = id;
          }
        });
        setActive(best);
      },
      { rootMargin: '-96px 0px -55% 0px', threshold: [0, 0.25, 0.5, 0.75, 1] },
    );

    for (const section of SECTIONS) {
      const el = document.getElementById(section.id);
      if (el) observer.observe(el);
    }
    return () => observer.disconnect();
  }, []);

  return active;
}

/**
 * Honour inbound `/#section` links (footer and header navigate here from other
 * public pages). The section may not be painted on the first frame after a
 * cross-page navigation, so retry briefly before giving up.
 */
function useHashScroll() {
  useEffect(() => {
    let timer = 0;
    let frame = 0;

    function followHash() {
      window.clearTimeout(timer);
      frame = 0;
      const hash = decodeURIComponent(window.location.hash.replace(/^#/, ''));
      if (!hash) return;
      function attempt() {
        if (document.getElementById(hash)) {
          scrollToId(hash);
          return;
        }
        frame += 1;
        if (frame < 30) timer = window.setTimeout(attempt, 50);
      }
      attempt();
    }

    followHash();
    window.addEventListener('hashchange', followHash);
    return () => {
      window.clearTimeout(timer);
      window.removeEventListener('hashchange', followHash);
    };
  }, []);
}

/** Fade-and-rise on first reveal; static content when reduced motion is on. */
function Reveal({
  children,
  delay = 0,
  className,
}: {
  children: React.ReactNode;
  delay?: number;
  className?: string;
}) {
  const [shown, setShown] = useState(false);
  const [node, setNode] = useState<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!node) return;
    if (prefersReducedMotion()) {
      setShown(true);
      return;
    }
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          setShown(true);
          observer.disconnect();
        }
      },
      { rootMargin: '0px 0px -10% 0px', threshold: 0.1 },
    );
    observer.observe(node);
    return () => observer.disconnect();
  }, [node]);

  return (
    <div
      ref={setNode}
      style={{ transitionDelay: `${delay}ms` }}
      className={clsx(
        'transition-all duration-500 ease-out motion-reduce:transition-none',
        shown ? 'translate-y-0 opacity-100' : 'translate-y-3 opacity-0',
        className,
      )}
    >
      {children}
    </div>
  );
}

function SectionHeading({
  eyebrowKey,
  titleKey,
  subtitleKey,
}: {
  eyebrowKey: string;
  titleKey: string;
  subtitleKey?: string;
}) {
  const { t } = useTranslation();
  return (
    <div className="mb-10 max-w-2xl">
      <p className="text-label-sm font-semibold uppercase tracking-[0.14em] text-secondary">
        {t(eyebrowKey)}
      </p>
      <h2 className="mt-3 font-display text-headline-lg text-on-surface">{t(titleKey)}</h2>
      {subtitleKey && (
        <p className="mt-3 text-body-lg text-on-surface-variant">{t(subtitleKey)}</p>
      )}
    </div>
  );
}

/* =================================== hero =================================== */

function Hero() {
  const { t } = useTranslation();
  const { user, signIn, signingIn } = useGoogleSignIn();

  return (
    <section className="border-b border-outline-variant/20">
      <div className="mx-auto grid w-full max-w-7xl items-center gap-10 px-4 py-14 sm:px-6 lg:gap-12 lg:px-8 lg:py-20">
        <Reveal>
          <div>
            <h1 className="font-display text-display-xl text-on-surface">
              {t('landing.hero.title')}
            </h1>
            <p className="mt-5 max-w-xl text-body-lg leading-relaxed text-on-surface-variant">
              {t('landing.hero.subtitle')}
            </p>

            <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
              <Button
                size="lg"
                onClick={signIn}
                loading={signingIn}
                rightIcon={!signingIn && <ArrowForward className="!text-lg" />}
                className="sm:min-w-44"
              >
                {t(user ? 'landing.hero.primarySignedIn' : 'landing.hero.primary')}
              </Button>
              <Button
                variant="secondary"
                size="lg"
                onClick={() => scrollToId('api')}
                leftIcon={<Code className="!text-lg" />}
                className="sm:min-w-44"
              >
                {t('landing.hero.secondaryApi')}
              </Button>
            </div>

            <p className="mt-7 flex items-start gap-2 text-metadata leading-relaxed text-outline">
              <Shield className="!text-base mt-px shrink-0 text-primary" />
              {t('landing.hero.trust')}
            </p>
          </div>
        </Reveal>

        <Reveal delay={120}>
          <ArchitectureFlowHero />
        </Reveal>
      </div>
    </section>
  );
}

/* ================================= features ================================ */

const FEATURE_META = [
  { key: 'aggregation', icon: Layers, span: 'lg:col-span-3' },
  { key: 'routing', icon: Route, span: 'lg:col-span-3' },
  { key: 'viewer', icon: PlayCircle, span: 'lg:col-span-2' },
  { key: 'api', icon: Code, span: 'lg:col-span-2' },
  { key: 'isolation', icon: AccountTree, span: 'lg:col-span-2' },
] as const;

function Features() {
  const { t } = useTranslation();

  return (
    <section id="features" className={clsx('border-b border-outline-variant/20 py-16 sm:py-20', SCROLL_MARGIN)}>
      <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
        <SectionHeading
          eyebrowKey="landing.features.eyebrow"
          titleKey="landing.features.title"
          subtitleKey="landing.features.subtitle"
        />
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-6">
          {FEATURE_META.map(({ key, icon: Icon, span }, index) => (
            <Reveal key={key} delay={index * 60} className={clsx('h-full', span)}>
              <Card hover className="flex h-full flex-col">
                <CardIconBox variant={index % 2 ? 'gold' : 'primary'} size="md">
                  <Icon className="!text-2xl" />
                </CardIconBox>
                <h3 className="mt-5 font-display text-body-lg font-semibold text-on-surface">
                  {t(`landing.features.items.${key}.title`)}
                </h3>
                <p className="mt-2 text-body-md leading-relaxed text-on-surface-variant">
                  {t(`landing.features.items.${key}.body`)}
                </p>
              </Card>
            </Reveal>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ================================= security ================================ */

const SECURITY_META = [
  { key: 'nonCustodial', icon: Shield },
  { key: 'token', icon: Lock },
  { key: 'limitedUse', icon: Security },
] as const;

function SecuritySection() {
  const { t } = useTranslation();

  return (
    <section id="security" className={clsx('border-b border-outline-variant/20 py-16 sm:py-20', SCROLL_MARGIN)}>
      <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
        <SectionHeading
          eyebrowKey="landing.security.eyebrow"
          titleKey="landing.security.title"
          subtitleKey="landing.security.subtitle"
        />
        <div className="grid gap-4 lg:grid-cols-3">
          {SECURITY_META.map(({ key, icon: Icon }, index) => (
            <Reveal key={key} delay={index * 80} className="h-full">
              <Card hover className="flex h-full flex-col">
                <CardIconBox variant={index === 1 ? 'gold' : 'primary'} size="md">
                  <Icon className="!text-2xl" />
                </CardIconBox>
                <h3 className="mt-5 font-display text-body-lg font-semibold text-on-surface">
                  {t(`landing.security.items.${key}.title`)}
                </h3>
                <p className="mt-2 text-body-md leading-relaxed text-on-surface-variant">
                  {t(`landing.security.items.${key}.body`)}
                </p>
              </Card>
            </Reveal>
          ))}
        </div>
        <Reveal delay={260}>
          <div className="mt-6">
            <Link
              href="/legal/security"
              className="inline-flex items-center gap-2 text-body-md font-semibold text-primary no-underline hover:underline"
            >
              <Shield className="!text-lg" />
              {t('landing.security.policyLink')}
              <ArrowForward className="!text-lg" />
            </Link>
          </div>
        </Reveal>
      </div>
    </section>
  );
}

/* =============================== developer api ============================== */

/** Lightweight teaser numbers: the full reference lives on the /docs portal. */
const TEASER_STATS = [
  { key: 'endpointLabel', value: TOTAL_ENDPOINTS },
  { key: 'groupLabel', value: ENDPOINT_GROUPS.length },
  { key: 'snippetLabel', value: SNIPPETS.length },
] as const;

function ApiSection() {
  const { t } = useTranslation();
  const sample = SNIPPETS.find((snippet) => snippet.id === 'upload');

  return (
    <section id="api" className={clsx('border-b border-outline-variant/20 py-16 sm:py-20', SCROLL_MARGIN)}>
      <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
        <SectionHeading
          eyebrowKey="landing.api.eyebrow"
          titleKey="landing.api.title"
          subtitleKey="landing.api.subtitle"
        />

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
          <Reveal>
            <Card hover className="flex h-full flex-col !p-6 sm:!p-7">
              <div className="flex items-center gap-3">
                <CardIconBox variant="primary" size="md">
                  <Code className="!text-2xl" />
                </CardIconBox>
                <h3 className="font-display text-body-lg font-semibold text-on-surface">
                  {t('landing.teaser.title')}
                </h3>
              </div>
              <p className="mt-4 text-body-md leading-relaxed text-on-surface-variant">
                {t('landing.teaser.subtitle')}
              </p>

              <dl className="mt-6 grid gap-3 sm:grid-cols-3">
                {TEASER_STATS.map((stat) => (
                  <div
                    key={stat.key}
                    className="rounded-xl bg-surface-container px-3 py-2.5 text-center"
                  >
                    <dd className="font-display text-headline-lg text-primary tabular-nums">
                      {stat.value}
                    </dd>
                    <dt className="mt-1 text-metadata uppercase tracking-wider text-outline">
                      {t(`landing.teaser.${stat.key}`)}
                    </dt>
                  </div>
                ))}
              </dl>

              <div className="mt-6 flex flex-col gap-3 border-t border-outline-variant/20 pt-5 sm:flex-row sm:items-center">
                <Link
                  href={DOCS_HREF}
                  className="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-container px-4 py-2.5 text-sm font-semibold text-on-primary-container no-underline transition-colors hover:bg-primary-container/80"
                >
                  <Terminal className="!text-lg" />
                  {t('landing.teaser.cta')}
                  <ArrowForward className="!text-lg" />
                </Link>
                <Link
                  href="/api-keys"
                  className="inline-flex items-center justify-center gap-2 rounded-xl border border-outline-variant/20 px-4 py-2.5 text-sm font-semibold text-on-surface no-underline transition-colors hover:bg-surface-container"
                >
                  <VpnKey className="!text-lg" />
                  {t('landing.teaser.keyCta')}
                </Link>
              </div>

              <p className="mt-4 text-metadata leading-relaxed text-outline">
                <Shield className="!text-base mr-1.5 inline-block align-[-2px] text-primary" />
                <Link
                  href="/legal/security"
                  className="text-on-surface-variant no-underline hover:text-primary hover:underline"
                >
                  {t('landing.teaser.securityCta')}
                </Link>
              </p>
            </Card>
          </Reveal>

          <Reveal delay={90} className="min-w-0">
            {sample && (
              <CodeBlock
                className="min-w-0"
                code={snippetCode(sample)}
                file={sample.file}
                labelKey="landing.teaser.ctaHint"
                maxHeightClass="max-h-80"
              />
            )}
          </Reveal>
        </div>
      </div>
    </section>
  );
}

/* =================================== page ================================== */

export default function LandingClient() {
  usePageTitle('landing.pageTitle');
  const activeSection = useScrollSpy();

  useHashScroll();

  useEffect(() => {
    const root = document.documentElement;
    const previous = root.style.scrollBehavior;
    if (!prefersReducedMotion()) root.style.scrollBehavior = 'smooth';
    return () => {
      root.style.scrollBehavior = previous;
    };
  }, []);

  return (
    <PublicShell activeSection={activeSection}>
      <Hero />
      <Features />
      <SecuritySection />
      <ApiSection />
    </PublicShell>
  );
}
