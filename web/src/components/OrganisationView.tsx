import { api, formatDate, relativeDays } from "../lib/api";
import { Empty, ErrorNote, Loading, Panel, useAsync } from "./ui";

interface OrgPayload {
  id: string;
  name: string;
  slug: string;
  circles: Array<{
    id: string; name: string; purpose: string; status: string;
    expires_at: string | null; closed_at: string | null;
  }>;
}

/**
 * An organisation's page (spec §12).
 *
 * It lists only the Circles *this* person belongs to. An organisation is a
 * billing and identity boundary here, not an access one — showing every Circle
 * in the company would contradict the whole model.
 */
export function OrganisationView({ slug }: { slug: string }) {
  const { data, error, loading } = useAsync<OrgPayload>(
    () => api.get<{ data: OrgPayload }>(`/organisations/${slug}`).then((r) => r.data),
    [slug],
  );

  if (loading)
    return (
      <main className="mx-auto max-w-[900px] px-6 py-10">
        <Panel>
          <Loading what="organisation" />
        </Panel>
      </main>
    );

  return (
    <main className="mx-auto max-w-[900px] px-6 py-10">
      {!!error && <ErrorNote error={error} />}

      {data && (
        <>
          <header className="mb-7">
            <p className="eyebrow">Organisation</p>
            <h1 className="display mt-1.5 text-[2rem] font-[680] leading-tight">{data.name}</h1>
            <p className="mt-2 max-w-xl text-[0.9375rem] leading-relaxed text-[var(--ink-muted)]">
              Circles you belong to. Membership of {data.name} does not, by
              itself, grant access to any of them.
            </p>
          </header>

          <Panel title="Your Circles here">
            {data.circles.length === 0 ? (
              <Empty>You are not a member of any Circle in this organisation.</Empty>
            ) : (
              <ul>
                {data.circles.map((c, i) => (
                  <li key={c.id} className="lay-in" style={{ animationDelay: `${i * 30}ms` }}>
                    <a
                      href={`/circles/${c.id}`}
                      className="block border-t border-[var(--rule)] px-5 py-4 no-underline transition-colors hover:bg-[var(--paper-sunk)]"
                    >
                      <div className="flex flex-wrap items-baseline justify-between gap-3">
                        <span className="display text-[1.0625rem] font-[600] text-[var(--ink)]">{c.name}</span>
                        <span className="text-xs text-[var(--ink-faint)]">
                          {c.closed_at ? `Closed ${formatDate(c.closed_at)}` : relativeDays(c.expires_at)}
                        </span>
                      </div>
                      <p className="mt-2 max-w-xl text-[0.9375rem] leading-relaxed text-[var(--ink-muted)]">{c.purpose}</p>
                    </a>
                  </li>
                ))}
              </ul>
            )}
          </Panel>
        </>
      )}
    </main>
  );
}
