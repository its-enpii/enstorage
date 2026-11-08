'use client';

import { useEffect, useRef } from 'react';

/**
 * Observe a sentinel element and invoke `onIntersect` when it scrolls into view.
 * Caller is responsible for guarding against re-entry (e.g. via a loading flag).
 *
 * `active` re-arms the observer: setting it to `false` disconnects (no events
 * while e.g. a page is loading) and flipping back to `true` starts a FRESH
 * observation, so a sentinel that is still intersecting fires again. Without
 * this, an IntersectionObserver only fires on *changes* of intersection state,
 * and an event swallowed during a transient window would never repeat.
 *
 * The callback is copied into a ref inside the effect (not during render), so
 * the observer always sees the latest closure without being torn down.
 */
export function useInfiniteScroll(
  onIntersect: () => void,
  options: { rootMargin?: string; enabled?: boolean; active?: boolean } = {},
) {
  const { rootMargin = '200px', enabled = true, active = true } = options;
  const ref = useRef<HTMLDivElement | null>(null);
  const cbRef = useRef(onIntersect);

  useEffect(() => {
    cbRef.current = onIntersect;
  });

  useEffect(() => {
    if (!enabled || !active) return;
    const el = ref.current;
    if (!el) return;
    const obs = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) cbRef.current();
        }
      },
      { rootMargin, threshold: 0 },
    );
    obs.observe(el);
    return () => obs.disconnect();
  }, [rootMargin, enabled, active]);

  return ref;
}
