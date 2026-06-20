'use client';

import { useEffect, useRef } from 'react';

/**
 * Observe a sentinel element and invoke `onIntersect` when it scrolls into view.
 * Caller is responsible for guarding against re-entry (e.g. via a loading flag).
 */
export function useInfiniteScroll(
  onIntersect: () => void,
  options: { rootMargin?: string; enabled?: boolean } = {},
) {
  const { rootMargin = '200px', enabled = true } = options;
  const ref = useRef<HTMLDivElement | null>(null);
  const cbRef = useRef(onIntersect);
  cbRef.current = onIntersect;

  useEffect(() => {
    if (!enabled) return;
    const el = ref.current;
    if (!el) return;
    const obs = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) cbRef.current();
        }
      },
      { rootMargin },
    );
    obs.observe(el);
    return () => obs.disconnect();
  }, [rootMargin, enabled]);

  return ref;
}
