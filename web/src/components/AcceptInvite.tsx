import { useEffect, useState } from "react";
import { api, getToken } from "../lib/api";
import { ErrorNote, Loading, Panel } from "./ui";

/** Redeems an invitation link, sending the visitor to sign in first if needed. */
export function AcceptInvite({ token }: { token: string }) {
  const [error, setError] = useState<unknown>(null);

  useEffect(() => {
    if (!getToken()) {
      location.href = `/login?next=${encodeURIComponent(location.pathname)}`;
      return;
    }

    api
      .post<{ data: { circle_id: string } }>(`/invitations/${token}/accept`)
      .then((res) => {
        location.href = `/circles/${res.data.circle_id}`;
      })
      .catch(setError);
  }, [token]);

  return (
    <main className="grid min-h-screen place-items-center px-6">
      <div className="w-full max-w-md">
        {error ? (
          <Panel title="This invitation could not be used" tone="signal">
            <div className="px-5 pb-5">
              <ErrorNote error={error} />
              <p className="mt-3 text-sm text-[var(--ink-muted)]">
                Invitations are bound to the email address they were issued to,
                expire after 14 days, and can only be used once. Ask the Circle
                owner to send a new one.
              </p>
            </div>
          </Panel>
        ) : (
          <Panel title="Joining"><Loading what="invitation" /></Panel>
        )}
      </div>
    </main>
  );
}
