'use client';

import { useEffect, useRef } from 'react';

/**
 * Observe a sentinel element and invoke `onIntersect` when it scrolls into view.
 *
 * One intersecting period fires exactly ONCE: after a fire, the observer stays
 * armed but silent until the sentinel actually leaves the viewport
 * (`isIntersecting === false`), which re-arms it. This makes a single stop at
 * the bottom produce a single trigger instead of a burst.
 *
 * `active` is kept for compatibility but is ONLY a temporary kill switch
 * (e.g. pause while a modal is open). Do NOT use it for re-arming: flipping it
 * back to `true` recreates the observer, and a sentinel that is still
 * intersecting fires again immediately — that is the cascade bug. Callers also
 * decide whether a trigger is allowed at all (e.g. skip while loading); the
 * hook adds no polling.
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
  const firedRef = useRef(false);

  useEffect(() => {
    cbRef.current = onIntersect;
  });

  useEffect(() => {
    if (!enabled || !active) return;
    const el = ref.current;
    if (!el) return;
    firedRef.current = false;
    const obs = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            if (!firedRef.current) {
              firedRef.current = true;
              cbRef.current();
            }
          } else if (entry.isIntersecting === false) {
            firedRef.current = false;
          }
        }
      },
      { rootMargin, threshold: 0 },
    );
    obs.observe(el);
    return () => obs.disconnect();
  }, [rootMargin, enabled, active]);

  return ref;
}
