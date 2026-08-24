import type { ReactNode } from "react";
import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";
import { ApiError } from "../lib/api";

/** Shared primitives. One card, one control shape, one accent. */

export function Panel({
  title,
  meta,
  children,
  tone = "default",
  className = "",
}: {
  title?: string;
  meta?: ReactNode;
  children: ReactNode;
  tone?: "default" | "derived" | "signal";
  className?: string;
}) {
  /*
    Tone is carried by a dot and the title colour, not by a bar across the card.
    A full-width red cap shouts at the same volume whether the card holds one
    blocker or a routine list, and two of them stacked read as an outage banner.
    A dot is found just as fast in a column of cards and stays part of the page.
  */
  const accent =
    tone === "derived"
      ? "var(--derived)"
      : tone === "signal"
        ? "var(--signal)"
        : null;

  return (
    <section className={`card overflow-hidden ${className}`}>
      {title && (
        <header className="flex flex-wrap items-center justify-between gap-3 px-5 pb-3 pt-4">
          <h2
            className="display flex items-center gap-2 text-[0.9375rem] font-[600] text-[var(--ink)]"
            style={accent ? { color: accent } : undefined}
          >
            {accent && (
              <span
                className="inline-block size-[7px] shrink-0 rounded-full"
                style={{ background: accent }}
                aria-hidden="true"
              />
            )}
            {title}
          </h2>
          {meta && <div className="flex items-center gap-2">{meta}</div>}
        </header>
      )}

      {children}
    </section>
  );
}

/** A quiet count or note to sit in a Panel's `meta` slot. */
export function Meta({ children }: { children: ReactNode }) {
  return <span className="text-xs text-[var(--ink-faint)] tabular">{children}</span>;
}

export function Button({
  children,
  onClick,
  variant = "default",
  type = "button",
  disabled,
  title,
  className = "",
}: {
  children: ReactNode;
  onClick?: () => void;
  variant?: "default" | "primary" | "danger" | "quiet";
  type?: "button" | "submit";
  disabled?: boolean;
  title?: string;
  className?: string;
}) {
  /*
    Four weights, in the platform's order of emphasis: a filled accent button
    for the one action a screen is for, a tinted grey for ordinary actions, a
    tinted red for destructive ones, and plain accent text for everything that
    should not compete. None of them shout in uppercase.
  */
  const styles = {
    primary:
      "bg-[var(--accent)] text-white shadow-[0_1px_2px_rgb(0_0_0/0.12)] hover:bg-[var(--accent-hover)] active:scale-[0.98]",
    default:
      "bg-[var(--paper-sunk)] text-[var(--ink)] hover:bg-[var(--rule-strong)] active:scale-[0.98]",
    danger:
      "bg-[var(--signal-soft)] text-[var(--signal)] hover:bg-[color-mix(in_srgb,var(--signal)_18%,transparent)] active:scale-[0.98]",
    quiet:
      "bg-transparent text-[var(--accent)] hover:bg-[var(--accent-soft)] active:scale-[0.98]",
  }[variant];

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={`inline-flex items-center justify-center gap-1.5 rounded-[var(--r-control)] px-3.5 py-2 text-[0.8125rem] font-[590] leading-none transition-all duration-150 disabled:pointer-events-none disabled:opacity-40 ${styles} ${className}`}
    >
      {children}
    </button>
  );
}

export function Field({
  label,
  hint,
  children,
}: {
  label: string;
  hint?: string;
  children: ReactNode;
}) {
  return (
    <label className="block">
      <span className="label block pb-1.5 !text-[var(--ink-muted)]">{label}</span>
      {children}
      {hint && (
        <span className="mt-1.5 block text-xs leading-snug text-[var(--ink-faint)]">
          {hint}
        </span>
      )}
    </label>
  );
}

/*
  Controls are inset rather than outlined: a soft fill with a hairline ring,
  which reads as a place to type without drawing a box around every field.
*/
const controlBase =
  "rounded-[var(--r-control)] bg-[var(--paper-inset)] px-3 py-2 text-[0.9375rem] text-[var(--ink)] " +
  "shadow-[inset_0_0_0_1px_var(--rule-strong)] transition-shadow duration-150 " +
  "placeholder:text-[var(--ink-faint)] hover:shadow-[inset_0_0_0_1px_var(--ink-faint)]";

/** Full-width control, for forms. */
export const inputClass = `w-full ${controlBase}`;

/**
 * Intrinsically-sized control, for filter bars. Kept separate rather than
 * layering `w-auto` over `w-full` — Tailwind emits utilities in a canonical
 * order, so which one wins is not decided by the order they appear here.
 */
export const filterClass = `${controlBase} !py-1.5 !text-[0.8125rem]`;

/** A dense definition row: label left, value right, aligned down a column. */
export function Fact({
  label,
  children,
  mono = false,
}: {
  label: string;
  children: ReactNode;
  mono?: boolean;
}) {
  return (
    <div className="flex items-baseline gap-4 py-1">
      <span className="label w-32 shrink-0">{label}</span>
      <span className={`min-w-0 text-sm ${mono ? "mono text-xs" : ""}`}>{children}</span>
    </div>
  );
}

export function Empty({ children }: { children: ReactNode }) {
  return (
    <p className="mx-auto max-w-sm px-5 py-10 text-center text-sm leading-relaxed text-[var(--ink-faint)]">
      {children}
    </p>
  );
}

export function ErrorNote({ error }: { error: unknown }) {
  if (!error) return null;

  const message =
    error instanceof ApiError
      ? error.message
      : error instanceof Error
        ? error.message
        : String(error);

  const isPolicy = error instanceof ApiError && error.status === 403;

  return (
    <div className="rounded-[var(--r-control)] bg-[var(--signal-soft)] px-4 py-3">
      <p className="text-[0.8125rem] font-[600] text-[var(--signal)]">
        {isPolicy ? "Refused by policy" : "Something went wrong"}
      </p>
      <p className="mt-0.5 text-sm leading-snug text-[var(--ink)]">{message}</p>
    </div>
  );
}

export function Loading({ what = "record" }: { what?: string }) {
  /*
    A skeleton rather than a spinner: the page keeps its shape while it loads,
    so nothing jumps when the data lands.
  */
  return (
    <div
      className="px-5 py-6"
      role="status"
      aria-live="polite"
      aria-label={`Loading ${what}`}
    >
      <div className="space-y-3">
        {[0, 1, 2].map((i) => (
          <div key={i} className="animate-pulse space-y-2" style={{ opacity: 1 - i * 0.25 }}>
            <div className="h-3 w-1/3 rounded-full bg-[var(--paper-sunk)]" />
            <div className="h-3 w-4/5 rounded-full bg-[var(--paper-sunk)]" />
          </div>
        ))}
      </div>
    </div>
  );
}

/** Copy-to-clipboard for hashes and ids, which are otherwise painful to quote. */
export function Copyable({ value, truncate = 0 }: { value: string; truncate?: number }) {
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (!copied) return;
    const t = setTimeout(() => setCopied(false), 1400);
    return () => clearTimeout(t);
  }, [copied]);

  const shown = truncate > 0 && value.length > truncate ? `${value.slice(0, truncate)}…` : value;

  return (
    <button
      type="button"
      title={copied ? "Copied" : `Copy ${value}`}
      onClick={() => {
        navigator.clipboard?.writeText(value);
        setCopied(true);
      }}
      className={`mono inline-flex items-center gap-1 rounded-md px-1 py-0.5 text-xs transition-colors duration-150 hover:bg-[var(--paper-sunk)] ${
        copied ? "text-[var(--settled)]" : "text-[var(--ink-muted)] hover:text-[var(--ink)]"
      }`}
    >
      {copied ? "Copied" : shown}
    </button>
  );
}

/*
  Values already fetched in this browsing context, keyed by caller-supplied
  name. It survives navigation because `ClientRouter` swaps the document
  instead of reloading it, and it is deliberately not persisted any further
  than that: a hard reload should always go back to the server.

  Only callers that pass `cacheKey` take part. That is on purpose — it is the
  right behaviour for a record that is identical on every view of a subject
  (the Circle itself), and the wrong behaviour for a list the user is editing.
*/
const shared = new Map<string, unknown>();

/*
  Layout effects do not run on the server, and asking for one there is a React
  warning rather than an error worth carrying. The distinction only matters on
  the client, where landing before paint is the whole point.
*/
const useIsomorphicLayoutEffect = typeof window === "undefined" ? useEffect : useLayoutEffect;

/** Forget one cached subject, or everything. Used when signing out. */
export function forgetCached(cacheKey?: string): void {
  if (cacheKey === undefined) shared.clear();
  else shared.delete(cacheKey);
}

/**
 * Wraps an async load into the states every view needs.
 *
 * The distinction that matters is between *loading* and *refreshing*. A view
 * arriving at a subject it has never shown has nothing to display, so it gets
 * the skeleton. A view re-reading a subject it is already showing must keep
 * showing it: blanking a list back to a skeleton because someone ticked one row
 * loses their scroll position, closes whatever they had open, and reads as the
 * page having reloaded — which is exactly what it should not do.
 *
 * `mutate` is the cheaper path still: when a write returns the updated record,
 * splice it in and skip the round trip entirely.
 */
export function useAsync<T>(
  loader: () => Promise<T>,
  deps: unknown[] = [],
  options: {
    /**
     * Share this subject across views for the life of the browsing context.
     * The cached value renders immediately and is re-read underneath, so the
     * caller sees `refreshing` rather than `loading`.
     */
    cacheKey?: string;
  } = {},
): {
  data: T | null;
  error: unknown;
  /** No data yet — show a skeleton. */
  loading: boolean;
  /** Data is on screen and being re-read underneath it. */
  refreshing: boolean;
  reload: () => void;
  mutate: (next: T | ((current: T) => T)) => void;
} {
  const { cacheKey } = options;

  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [nonce, setNonce] = useState(0);

  // Identifies *which subject* is loaded, so a `reload()` of the same subject
  // can be told apart from a move to a different one.
  const key = JSON.stringify(deps);
  const loaded = useRef<string | null>(null);

  // `mutate` must write through to the cache without re-subscribing on every
  // render, so the key it writes to is read from a ref.
  const cacheKeyRef = useRef(cacheKey);
  cacheKeyRef.current = cacheKey;

  useIsomorphicLayoutEffect(() => {
    let cancelled = false;
    const cached = cacheKey === undefined ? undefined : (shared.get(cacheKey) as T | undefined);
    const revalidating = loaded.current === key;

    if (cached !== undefined) {
      // Either the seed from this mount, or a subject we have just moved to
      // that another view already fetched. Both are worth showing at once.
      setData(cached);
      setLoading(false);
      setRefreshing(true);
      loaded.current = key;
    } else if (revalidating) {
      setRefreshing(true);
    } else {
      setLoading(true);
      setData(null);
    }
    setError(null);

    loader()
      .then((result) => {
        if (cancelled) return;
        loaded.current = key;
        if (cacheKey !== undefined) shared.set(cacheKey, result);
        setData(result);
      })
      .catch((e) => {
        if (!cancelled) setError(e);
      })
      .finally(() => {
        if (cancelled) return;
        setLoading(false);
        setRefreshing(false);
      });

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, nonce, cacheKey]);

  const mutate = useCallback((next: T | ((current: T) => T)) => {
    setData((current) => {
      const value =
        typeof next === "function"
          ? current === null
            ? current
            : (next as (c: T) => T)(current)
          : next;
      // A write that lands here is newer than anything cached, so views that
      // mount later must not be handed the stale copy.
      if (cacheKeyRef.current !== undefined && value !== null) {
        shared.set(cacheKeyRef.current, value);
      }
      return value;
    });
  }, []);

  return { data, error, loading, refreshing, reload: () => setNonce((n) => n + 1), mutate };
}
