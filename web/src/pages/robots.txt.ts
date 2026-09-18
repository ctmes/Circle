import type { APIRoute } from "astro";
import { SITE, isPlaceholder } from "../lib/site";

/**
 * robots.txt.
 *
 * The application routes are disallowed because they are behind a bearer token
 * and render an empty shell to anyone without one — a crawler would index a
 * page of nothing under a URL nobody can use.
 *
 * While the site origin is still a placeholder the whole site is disallowed.
 * Publishing a staging build that gets indexed under the wrong hostname is the
 * kind of mistake that takes months to undo, and this makes it impossible.
 */
const appRoutes = ["/circles", "/login", "/invitations", "/organisations"];

export const GET: APIRoute = () => {
  const body = isPlaceholder(SITE.origin)
    ? [
        "# Site origin is not configured yet — see web/PLACEHOLDERS.md.",
        "# Indexing is disabled until it is.",
        "User-agent: *",
        "Disallow: /",
        "",
      ].join("\n")
    : [
        "User-agent: *",
        "Allow: /",
        ...appRoutes.map((route) => `Disallow: ${route}`),
        "",
        `Sitemap: ${SITE.origin}/sitemap.xml`,
        "",
      ].join("\n");

  return new Response(body, {
    headers: {
      "Content-Type": "text/plain; charset=utf-8",
      "Cache-Control": "public, max-age=3600",
    },
  });
};
