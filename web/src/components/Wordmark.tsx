/**
 * The product mark.
 *
 * A ring with a filled centre: the boundary and the thing it is drawn around.
 * It appeared inline in three places with three different sets of numbers, so
 * it lives here now and scales from one prop.
 */
export function Wordmark({
  size = 17,
  tagline,
}: {
  size?: number;
  tagline?: string;
}) {
  return (
    <span className="inline-flex items-center gap-2.5">
      <svg
        width={size * 1.5}
        height={size * 1.5}
        viewBox="0 0 32 32"
        aria-hidden="true"
        className="shrink-0"
      >
        <circle
          cx="16"
          cy="16"
          r="12"
          fill="none"
          stroke="currentColor"
          strokeWidth="2.25"
        />
        <circle cx="16" cy="16" r="4.5" fill="var(--accent)" />
      </svg>

      <span>
        <span
          className="display block font-[650] leading-none text-[var(--ink)]"
          style={{ fontSize: size }}
        >
          Circle
        </span>
        {tagline && (
          <span className="mt-1.5 block text-[0.8125rem] leading-snug text-[var(--ink-muted)]">
            {tagline}
          </span>
        )}
      </span>
    </span>
  );
}
