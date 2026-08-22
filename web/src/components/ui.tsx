import type { ReactNode } from "react";
import { useEffect, useState } from "react";
import { ApiError } from "../lib/api";

/** Shared primitives, styled for the document-control language. */

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
  const border =
    tone === "derived"
      ? "border-[var(--derived)]"
      : tone === "signal"
        ? "border-[var(--signal)]"
        : "border-[var(--rule-strong)]";

  return (
    <section
      className={`border ${border} bg-[var(--paper-raised)] ${className}`}
    >
      {title && (
        <header className="flex flex-wrap items-baseline justify-between gap-3 border-b border-[var(--rule)] px-4 py-2.5">
          <h2 className="label !text-[0.6875rem] !text-[var(--ink)]">{title}</h2>
          {meta && <div className="flex items-center gap-2">{meta}</div>}
        </header>
      )}
      {children}
    </section>
  );
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
  const styles = {
    default:
      "border-[var(--ink)] text-[var(--ink)] hover:bg-[var(--ink)] hover:text-[var(--paper)]",
    primary:
      "border-[var(--ink)] bg-[var(--ink)] text-[var(--paper)] hover:bg-transparent hover:text-[var(--ink)]",
    danger:
      "border-[var(--signal)] text-[var(--signal)] hover:bg-[var(--signal)] hover:text-[var(--paper)]",
    quiet:
      "border-transparent text-[var(--ink-muted)] hover:border-[var(--rule-strong)] hover:text-[var(--ink)]",
  }[variant];

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      title={title}
      className={`display border px-3 py-1.5 text-xs font-600 uppercase tracking-[0.1em] transition-colors duration-150 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent disabled:hover:text-current ${styles} ${className}`}
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
      <span className="label block pb-1">{label}</span>
      {children}
      {hint && (
        <span className="mt-1 block text-xs text-[var(--ink-faint)]">{hint}</span>
      )}
    </label>
  );
}

const controlBase =
  "border border-[var(--rule-strong)] bg-[var(--paper)] px-2.5 py-1.5 font-[var(--font-body)] text-sm text-[var(--ink)] placeholder:text-[var(--ink-faint)]";

/** Full-width control, for forms. */
export const inputClass = `w-full ${controlBase}`;

/**
 * Intrinsically-sized control, for filter bars. Kept separate rather than
 * layering `w-auto` over `w-full` — Tailwind emits utilities in a canonical
 * order, so which one wins is not decided by the order they appear here.
 */
export const filterClass = controlBase;

/** A dense definition row, as found in a drawing title block. */
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
    <div className="flex items-baseline gap-3 py-1">
      <span className="label w-32 shrink-0">{label}</span>
      <span className={`text-sm ${mono ? "mono text-xs" : ""}`}>{children}</span>
    </div>
  );
}

export function Empty({ children }: { children: ReactNode }) {
  return (
    <p className="px-4 py-8 text-center text-sm italic text-[var(--ink-faint)]">
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
    <div className="border border-[var(--signal)] bg-[var(--signal-soft)] px-4 py-3">
      <p className="label !text-[var(--signal)]">
        {isPolicy ? "Refused by policy" : "Something went wrong"}
      </p>
      <p className="mt-1 text-sm text-[var(--ink)]">{message}</p>
    </div>
  );
}

export function Loading({ what = "record" }: { what?: string }) {
  return (
    <p className="px-4 py-8 text-center label">
      <span className="inline-block animate-pulse">reading the {what}…</span>
    </p>
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
      className="mono text-xs text-[var(--ink-muted)] underline decoration-dotted underline-offset-2 hover:text-[var(--ink)]"
    >
      {copied ? "copied" : shown}
    </button>
  );
}

/** Wraps an async load into the three states every view needs. */
export function useAsync<T>(
  loader: () => Promise<T>,
  deps: unknown[] = [],
): { data: T | null; error: unknown; loading: boolean; reload: () => void } {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);
  const [nonce, setNonce] = useState(0);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);

    loader()
      .then((result) => {
        if (!cancelled) setData(result);
      })
      .catch((e) => {
        if (!cancelled) setError(e);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, nonce]);

  return { data, error, loading, reload: () => setNonce((n) => n + 1) };
}
