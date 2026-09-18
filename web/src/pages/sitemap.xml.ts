import type { APIRoute } from "astro";
import { SITE, url, isPlaceholder } from "../lib/site";

/**
 * The sitemap.
 *
 * Written by hand rather than generated, because @astrojs/sitemap enumerates
 * routes and this app's routes are mostly dynamic Circle pages that must never
 * appear here. The public surface is small enough to list, and a list that is
 * wrong is obvious.
 */
const pages: { path: string; priority: string; changefreq: string }[] = [
  { path: "/", priority: "1.0", changefreq: "monthly" },
  { path: "/product", priority: "0.9", changefreq: "monthly" },
  { path: "/security", priority: "0.8", changefreq: "monthly" },
  { path: "/contact", priority: "0.8", changefreq: "yearly" },
  { path: "/legal", priority: "0.4", changefreq: "yearly" },
  { path: "/legal/terms", priority: "0.4", changefreq: "yearly" },
  { path: "/legal/privacy", priority: "0.4", changefreq: "yearly" },
  { path: "/legal/dpa", priority: "0.3", changefreq: "yearly" },
  { path: "/legal/subprocessors", priority: "0.3", changefreq: "monthly" },
  { path: "/legal/acceptable-use", priority: "0.3", changefreq: "yearly" },
  { path: "/legal/cookies", priority: "0.3", changefreq: "yearly" },
  { path: "/legal/responsible-disclosure", priority: "0.3", changefreq: "yearly" },
];

export const GET: APIRoute = () => {
  /* Without a real hostname every <loc> would be an invalid URL, so the sitemap
     is served empty rather than wrong. robots.txt disallows the site under the
     same condition, so nothing is looking for it yet anyway. */
  if (isPlaceholder(SITE.origin)) {
    return new Response(
      `<?xml version="1.0" encoding="UTF-8"?>
<!-- Site origin is not configured yet — see web/PLACEHOLDERS.md. -->
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>
`,
      { headers: { "Content-Type": "application/xml; charset=utf-8" } },
    );
  }

  /* SITE.legalEffective is human-readable ("24 August 2026"); the sitemap needs
     a W3C date, so it is parsed once and falls back to today if someone edits it
     into a form Date cannot read. */
  const parsed = new Date(SITE.legalEffective);
  const lastmod = (Number.isNaN(parsed.valueOf()) ? new Date() : parsed)
    .toISOString()
    .slice(0, 10);

  const xml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
${pages
  .map(
    (page) => `  <url>
    <loc>${url(page.path)}</loc>
    <lastmod>${lastmod}</lastmod>
    <changefreq>${page.changefreq}</changefreq>
    <priority>${page.priority}</priority>
  </url>`,
  )
  .join("\n")}
</urlset>
`;

  return new Response(xml, {
    headers: {
      "Content-Type": "application/xml; charset=utf-8",
      "Cache-Control": "public, max-age=3600",
    },
  });
};
