'use client';

import { useCallback, useEffect, useRef } from 'react';

/**
 * Observe a sentinel element and invoke `onIntersect` when it scrolls into view.
 *
 * The hook returns a CALLBACK REF (not a RefObject): pass it to the sentinel
 * (`ref={attach}`). Because the observer is created from the callback itself,
 * it no longer depends on the element being present when an effect first runs
 * (`ref.current === null` used to silently disable the observer forever).
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
 * The callback is copied into a ref on every render (not read inside the
 * observer), so the observer always sees the latest closure without being
 * torn down.
 */
export function useInfiniteScroll(
  onIntersect: () => void,
  options: { rootMargin?: string; enabled?: boolean; active?: boolean } = {},
) {
  const { rootMargin = '1200px', enabled = true, active = true } = options;
  const elRef = useRef<HTMLDivElement | null>(null);
  const obsRef = useRef<IntersectionObserver | null>(null);
  const cbRef = useRef(onIntersect);
  cbRef.current = onIntersect;
  const optsRef = useRef({ rootMargin, enabled, active });
  optsRef.current = { rootMargin, enabled, active };
  const firedRef = useRef(false);

  const attach = useCallback((el: HTMLDivElement | null) => {
    obsRef.current?.disconnect();
    obsRef.current = null;
    elRef.current = el;
    if (!el) return;
    const { rootMargin: rm, enabled: en, active: ac } = optsRef.current;
    if (!en || !ac) return;
    firedRef.current = false;
    const obs = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            if (!firedRef.current) {
              firedRef.current = true;
              cbRef.current();
            }
          } else {
            firedRef.current = false;
          }
        }
      },
      { rootMargin: rm, threshold: 0 },
    );
    obs.observe(el);
    obsRef.current = obs;
  }, []);

  // Re-arm when enabled/active/rootMargin change, using the element captured
  // by the callback ref (no dependence on mount-time ref presence).
  useEffect(() => {
    if (elRef.current) attach(elRef.current);
    return () => {
      obsRef.current?.disconnect();
      obsRef.current = null;
    };
  }, [attach, rootMargin, enabled, active]);

  return attach;
}
