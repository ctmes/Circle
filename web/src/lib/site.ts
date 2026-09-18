/**
 * Every fact about the company that appears on the public site or in a legal
 * document lives here once.
 *
 * Anything wrapped in [square brackets] is a PLACEHOLDER and must be replaced
 * before the site goes live. `PLACEHOLDERS.md` in this directory's parent lists
 * them with the reason each one matters. Nothing in this file is invented —
 * where a real value was not known, the placeholder was left visible rather
 * than filled with something plausible, because a legal document that names the
 * wrong entity is worse than one that obviously names none.
 */

/** True for any value still carrying its placeholder brackets. */
export const isPlaceholder = (value: string) => /\[.+\]/.test(value);

export const SITE = {
  /* ------------------------------------------------------------- identity */

  name: "Circle",
  tagline:
    "A trusted, temporary operating environment for organisations collaborating on a consequential outcome.",

  /** Used for canonical URLs, sitemap, robots and Open Graph. No trailing slash. */
  origin: "[https://circle.example]",

  /* ---------------------------------------------------------- legal entity */

  legal: {
    /** The company that contracts with customers and controls the data. */
    entity: "[Legal entity name] Pty Ltd",
    /** Australian Business Number. Shown in the footer and in the Terms. */
    abn: "[ABN 00 000 000 000]",
    /** Registered office. Required on a privacy policy under APP 1.4. */
    address: "[Street address], [Suburb] [STATE] [Postcode], Australia",
    /** Governing law and venue for the Terms. */
    jurisdiction: "New South Wales, Australia",
    /** Where the production database and object storage actually sit. */
    dataRegion: "[ap-southeast-2 (Sydney), Australia]",
  },

  /* -------------------------------------------------------------- contact */

  email: {
    general: "[hello@circle.example]",
    sales: "[pilots@circle.example]",
    privacy: "[privacy@circle.example]",
    legal: "[legal@circle.example]",
    security: "[security@circle.example]",
    support: "[support@circle.example]",
  },

  /* -------------------------------------------------------------- dates */

  /**
   * The date the current published version of the legal documents took effect.
   * Change this whenever a legal document changes materially, and give existing
   * customers the notice period the Terms promise (30 days).
   */
  legalEffective: "24 August 2026",
  legalVersion: "1.0",

  /* ------------------------------------------------------------ maturity */

  /**
   * Said plainly on the site because it is true, and because a buyer who finds
   * out later that "trusted by teams everywhere" meant "nobody yet" will not
   * come back. Replace with real figures as they exist.
   */
  stage: {
    availability: "Pilot",
    line: "Circle is in scoped pilots. It is not yet generally available.",
  },
} as const;

/** Convenience: an absolute URL for a site-relative path. */
export const url = (path: string) =>
  `${SITE.origin}${path.startsWith("/") ? path : `/${path}`}`;
