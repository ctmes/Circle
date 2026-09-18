import { useEffect, useRef, useState } from "react";
import { api, type Circle } from "../lib/api";

/**
 * The Circle's state, drawn as the mark itself.
 *
 * The ring is the same geometry as the Wordmark, and it fills as the mission
 * advances — so an open Circle is a ring with a gap in it and a finished one is
 * a ring that has closed. The percentage is *stated* by whoever runs the
 * Circle, never inferred from counting commitments: a count of open tasks is a
 * different claim, and dressing it up as progress would be a quiet lie.
 *
 * A closed Circle keeps whatever figure it closed at. Closing at 60% is a real
 * outcome and the record should say so rather than round it up.
 */

const R = 12;
const CIRCUMFERENCE = 2 * Math.PI * R;

export function progressTone(circle: {
  progress: number;
  is_closed: boolean;
  is_expired: boolean;
}): string {
  if (circle.is_closed) return "var(--ink-faint)";
  if (circle.is_expired) return "var(--signal)";
  if (circle.progress >= 100) return "var(--settled)";
  return "var(--accent)";
}

export function Ring({
  progress,
  tone,
  size = 22,
  strokeWidth = 2.75,
}: {
  progress: number;
  tone: string;
  size?: number;
  strokeWidth?: number;
}) {
  const filled = (CIRCUMFERENCE * Math.max(0, Math.min(100, progress))) / 100;

  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 32 32"
      className="shrink-0 overflow-visible"
      aria-hidden="true"
    >
      {/* The unfinished part of the mission, always drawn, so the gap in an
          open Circle reads as a gap rather than as a missing element. */}
      <circle
        cx="16"
        cy="16"
        r={R}
        fill="none"
        stroke="var(--rule-strong)"
        strokeWidth={strokeWidth}
      />
      <circle
        cx="16"
        cy="16"
        r={R}
        fill="none"
        stroke={tone}
        strokeWidth={strokeWidth}
        strokeLinecap="round"
        strokeDasharray={`${filled} ${CIRCUMFERENCE - filled}`}
        transform="rotate(-90 16 16)"
        style={{ transition: "stroke-dasharray 420ms cubic-bezier(0.22, 0.61, 0.36, 1)" }}
      />
      {/* The centre, as on the mark: the thing the boundary is drawn around.
          Smaller than the Wordmark's, so the gap either side of the arc stays
          readable at 18px — this one has to carry a figure, not just identity. */}
      <circle cx="16" cy="16" r="3.5" fill={tone} />
    </svg>
  );
}

/** Ring, state word, and figure — the read-only form. */
export function CircleMeter({
  circle,
  size = 24,
  className = "",
}: {
  circle: Pick<Circle, "progress" | "is_closed" | "is_expired" | "status">;
  size?: number;
  className?: string;
}) {
  const tone = progressTone(circle);
  const open = !circle.is_closed;

  return (
    <span
      className={`inline-flex items-center gap-2 ${className}`}
      role="progressbar"
      aria-valuenow={circle.progress}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-label={`${open ? "Circle open" : "Circle closed"}, ${circle.progress}% complete`}
    >
      <Ring progress={circle.progress} tone={tone} size={size} />
      <span className="inline-flex items-baseline gap-1.5 text-[0.8125rem] leading-none">
        <span className="font-[560] text-[var(--ink)]">
          Circle {open ? "open" : "closed"}
        </span>
        <span className="tabular text-[var(--ink-muted)]">{circle.progress}%</span>
      </span>
    </span>
  );
}

/**
 * The same meter, but settable.
 *
 * Only shown to someone the gate would actually let through, so the control is
 * never offered and then refused. The slider previews live and the figure is
 * committed on release — dragging through 40 intermediate values should not
 * write 40 audit events.
 */
export function CircleMeterControl({
  circle,
  onSaved,
}: {
  circle: Circle;
  /** Handed the saved Circle, so the caller can update in place rather than re-read. */
  onSaved: (circle: Circle) => void;
}) {
  const [open, setOpen] = useState(false);
  const [value, setValue] = useState(circle.progress);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => setValue(circle.progress), [circle.progress]);

  useEffect(() => {
    if (!open) return;

    function onDocument(e: MouseEvent | KeyboardEvent) {
      if (e instanceof KeyboardEvent) {
        if (e.key === "Escape") setOpen(false);
        return;
      }
      if (box.current && !box.current.contains(e.target as Node)) setOpen(false);
    }

    document.addEventListener("mousedown", onDocument);
    document.addEventListener("keydown", onDocument);
    return () => {
      document.removeEventListener("mousedown", onDocument);
      document.removeEventListener("keydown", onDocument);
    };
  }, [open]);

  async function commit(next: number) {
    if (next === circle.progress) return;
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.patch<{ data: Circle }>(`/circles/${circle.id}`, {
        progress: next,
      });
      onSaved(data);
    } catch (e) {
      setValue(circle.progress);
      setError(e instanceof Error ? e.message : "Could not save.");
    } finally {
      setBusy(false);
    }
  }

  // The preview follows the slider; the tone stays the tone of the saved state.
  const preview = { ...circle, progress: value };

  return (
    <div ref={box} className="relative">
      <button
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        title="Set how far along this Circle is"
        className={`-mx-1.5 -my-1 inline-flex items-center rounded-[var(--r-control)] px-1.5 py-1 transition-colors duration-150 hover:bg-[var(--paper-sunk)] ${
          busy ? "opacity-60" : ""
        }`}
      >
        <CircleMeter circle={preview} />
      </button>

      {open && (
        <div className="card absolute left-0 top-[calc(100%+0.5rem)] z-30 w-[17rem] p-4 shadow-[var(--shadow-ring),var(--shadow-float)]">
          <div className="flex items-baseline justify-between">
            <span className="label !text-[var(--ink-muted)]">Progress</span>
            <span className="tabular display text-[1.25rem] font-[640] leading-none text-[var(--ink)]">
              {value}%
            </span>
          </div>

          <input
            type="range"
            min={0}
            max={100}
            step={5}
            value={value}
            disabled={busy}
            onChange={(e) => setValue(Number(e.target.value))}
            onPointerUp={() => commit(value)}
            onKeyUp={() => commit(value)}
            aria-label="Percent complete"
            className="mt-3 w-full"
          />

          <div className="mt-3 flex gap-1.5">
            {[0, 25, 50, 75, 100].map((p) => (
              <button
                key={p}
                disabled={busy}
                onClick={() => {
                  setValue(p);
                  commit(p);
                }}
                className={`tabular flex-1 rounded-[var(--r-control)] py-1.5 text-xs font-[560] transition-colors duration-150 ${
                  value === p
                    ? "bg-[var(--accent)] text-white"
                    : "bg-[var(--paper-sunk)] text-[var(--ink-muted)] hover:text-[var(--ink)]"
                }`}
              >
                {p}
              </button>
            ))}
          </div>

          <p
            className={`mt-3 text-xs leading-snug ${
              error ? "text-[var(--signal)]" : "text-[var(--ink-faint)]"
            }`}
          >
            {error ?? "Recorded in this Circle's history, with who set it."}
          </p>
        </div>
      )}
    </div>
  );
}
