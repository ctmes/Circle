import type {
  IntegrityStatus,
  OriginStatus,
  ProcessingStatus,
  ReviewStatus,
} from "../lib/api";

/**
 * The trust vocabulary, rendered.
 *
 * Spec §6 is explicit: no generic green "verified" badge. Origin, integrity and
 * review are three independent facts and are always shown as three separate
 * marks, each with its own wording. A document can legitimately be
 * "authenticated upload · intact · unreviewed", and the UI must be able to say
 * exactly that rather than collapsing it into a tick or a cross.
 *
 * Visually each mark is a soft tinted pill. The tint carries the tone at a
 * glance; the words carry the meaning on a closer read. Nothing is a bare
 * colour — a mark that only signals by hue would fail for anyone who cannot
 * separate the hues.
 */

type Tone = "neutral" | "settled" | "signal" | "derived" | "faint";

const TONE_STYLES: Record<Tone, string> = {
  neutral: "text-[var(--ink-muted)] bg-[var(--paper-sunk)]",
  settled: "text-[var(--settled)] bg-[var(--settled-soft)]",
  signal: "text-[var(--signal)] bg-[var(--signal-soft)]",
  derived: "text-[var(--derived)] bg-[var(--derived-soft)]",
  faint: "text-[var(--ink-faint)] bg-[var(--paper-sunk)]",
};

function Mark({
  tone,
  label,
  value,
  title,
}: {
  tone: Tone;
  label: string;
  value: string;
  title: string;
}) {
  return (
    <span
      title={title}
      className={`inline-flex items-center gap-1.5 rounded-[var(--r-chip)] px-2 py-[3px] ${TONE_STYLES[tone]}`}
    >
      <span className="text-[0.625rem] font-[640] uppercase tracking-[0.06em] opacity-60">
        {label}
      </span>
      <span className="text-[0.6875rem] font-[560] leading-none">{value}</span>
    </span>
  );
}

const ORIGIN: Record<OriginStatus, { tone: Tone; text: string; title: string }> = {
  verified_source: {
    tone: "settled",
    text: "verified source",
    title: "Pulled straight from a connected system we authenticated against.",
  },
  authenticated_upload: {
    tone: "neutral",
    text: "authenticated upload",
    title:
      "Uploaded by a known Circle participant. That tells you who put it here, not that what's in it is true.",
  },
  unverified_upload: {
    tone: "signal",
    text: "unverified upload",
    title: "The uploader or source could not be reliably identified.",
  },
  attested: {
    tone: "neutral",
    text: "attested",
    title: "A verified identity made a statement about this item.",
  },
};

const INTEGRITY: Record<IntegrityStatus, { tone: Tone; text: string; title: string }> = {
  intact: {
    tone: "settled",
    text: "intact",
    title: "The stored bytes still match the SHA-256 recorded at upload.",
  },
  superseded: {
    tone: "faint",
    text: "superseded",
    title: "There is a newer version of this item. This one is kept and can still be cited.",
  },
  changed: {
    tone: "signal",
    text: "changed",
    title: "The stored bytes no longer match the recorded hash. Don't trust this version.",
  },
  unknown: {
    tone: "faint",
    text: "unknown",
    title: "Integrity has not been computed yet.",
  },
};

const REVIEW: Record<ReviewStatus, { tone: Tone; text: string; title: string }> = {
  unreviewed: { tone: "faint", text: "unreviewed", title: "Nobody has reviewed this yet." },
  reviewed: { tone: "settled", text: "reviewed", title: "A reviewer has examined this." },
  approved: { tone: "settled", text: "approved", title: "Approved against an exact version." },
  derived: {
    tone: "derived",
    text: "derived",
    title: "Machine-generated. Needs a person to check it before it is relied on.",
  },
  contested: { tone: "signal", text: "contested", title: "Someone has formally disputed this." },
  stale: { tone: "signal", text: "stale", title: "Flagged as possibly out of date." },
};

export function OriginMark({ status }: { status: OriginStatus }) {
  const s = ORIGIN[status];
  return <Mark tone={s.tone} label="src" value={s.text} title={s.title} />;
}

export function IntegrityMark({ status }: { status: IntegrityStatus }) {
  const s = INTEGRITY[status];
  return <Mark tone={s.tone} label="int" value={s.text} title={s.title} />;
}

export function ReviewMark({ status }: { status: ReviewStatus }) {
  const s = REVIEW[status];
  return <Mark tone={s.tone} label="rev" value={s.text} title={s.title} />;
}

/** All three axes together — the trust record on an evidence item. */
export function TrustStamp({
  origin,
  integrity,
  review,
}: {
  origin: OriginStatus;
  integrity: IntegrityStatus;
  review: ReviewStatus;
}) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      <OriginMark status={origin} />
      <IntegrityMark status={integrity} />
      <ReviewMark status={review} />
    </div>
  );
}

/**
 * The derived label. Spec §9 requires every agent output to carry it, so this
 * component exists to make it impossible to render agent content without one.
 */
export function DerivedStamp({ compact = false }: { compact?: boolean }) {
  const dot = (
    <span
      className="inline-block size-1.5 rounded-full bg-current"
      aria-hidden="true"
    />
  );

  if (compact) {
    return (
      <span
        className="inline-flex items-center gap-1.5 rounded-[var(--r-chip)] bg-[var(--derived-soft)] px-2 py-[3px] text-[0.6875rem] font-[560] text-[var(--derived)]"
        title="Generated by an agent. Needs a person to check it."
      >
        {dot}
        Derived
      </span>
    );
  }

  return (
    <span
      className="inline-flex items-center gap-1.5 rounded-[var(--r-chip)] bg-[var(--derived-soft)] px-2.5 py-1 text-[0.75rem] font-[560] text-[var(--derived)]"
      title="Generated by an agent from cited evidence. It is a proposal for a person to check, not a finding."
    >
      {dot}
      Derived by agent · needs a person to check it
    </span>
  );
}

const PROCESSING: Record<ProcessingStatus, { tone: Tone; text: string }> = {
  pending: { tone: "faint", text: "queued" },
  processing: { tone: "neutral", text: "working" },
  ready: { tone: "settled", text: "ready" },
  failed: { tone: "signal", text: "failed" },
  skipped: { tone: "faint", text: "n/a" },
};

/** Per-lane extraction state. "n/a" is a real answer, not a silent blank. */
export function PipelineMark({
  label,
  status,
}: {
  label: string;
  status: ProcessingStatus;
}) {
  const s = PROCESSING[status];
  return (
    <span className="inline-flex items-center gap-1.5">
      <span className="text-xs text-[var(--ink-faint)]">{label}</span>
      <span
        className={`text-xs font-[560] ${
          s.tone === "signal"
            ? "text-[var(--signal)]"
            : s.tone === "settled"
              ? "text-[var(--settled)]"
              : "text-[var(--ink-muted)]"
        }`}
      >
        {s.text}
      </span>
    </span>
  );
}

const CLAIM_STATUS: Record<string, Tone> = {
  draft: "faint",
  attested: "neutral",
  derived: "derived",
  under_review: "neutral",
  reviewed: "settled",
  approved: "settled",
  contested: "signal",
  rejected: "signal",
};

export function StatusChip({
  status,
  tone,
}: {
  status: string;
  tone?: Tone;
}) {
  const resolved = tone ?? CLAIM_STATUS[status] ?? "neutral";
  return (
    <span
      className={`inline-block whitespace-nowrap rounded-[var(--r-chip)] px-2.5 py-[3px] text-[0.6875rem] font-[560] leading-[1.4] ${TONE_STYLES[resolved]}`}
    >
      {status.replace(/_/g, " ")}
    </span>
  );
}

export const DECISION_TONE: Record<string, Tone> = {
  draft: "faint",
  pending: "signal",
  approved: "settled",
  rejected: "signal",
  expired: "faint",
  superseded: "faint",
};

export const COMMITMENT_TONE: Record<string, Tone> = {
  draft: "faint",
  open: "neutral",
  blocked: "signal",
  done: "settled",
  cancelled: "faint",
};
