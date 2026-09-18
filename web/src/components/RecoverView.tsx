import { useState } from "react";
import { api, setToken } from "../lib/api";
import { Button, ErrorNote, Field, inputClass } from "./ui";
import { ThemeToggle } from "./ThemeToggle";
import { Wordmark } from "./Wordmark";

/**
 * Losing and regaining a password.
 *
 * One component for both halves, because they are one errand and the person
 * walking it is already having a bad minute. Which half shows depends on
 * whether the URL carries a token — arriving from the email skips straight to
 * the part that matters.
 *
 * Deliberately says nothing about whether an address is registered. The API
 * answers identically either way, and a page that said "no such account" would
 * undo that in the interface.
 */
export function RecoverView({
  token = "",
  emailFromLink = "",
}: {
  /**
   * Read from the query string by the Astro page and handed down, rather than
   * read from `location` here. The component renders on the server too, where
   * there is no `location` — reading it here would server-render the "ask for a
   * link" half and then hydrate into the "set a password" half, which is a
   * mismatch React resolves by rendering the page twice in front of somebody
   * who is already locked out.
   */
  token?: string;
  emailFromLink?: string;
}) {
  const [email, setEmail] = useState(emailFromLink);
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const redeeming = token !== "";

  async function request() {
    setBusy(true);
    setError(null);

    try {
      await api.post("/auth/forgot-password", { email });
      setSent(true);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  async function redeem() {
    setBusy(true);
    setError(null);

    try {
      const res = await api.post<{ token: string }>("/auth/reset-password", {
        token,
        email,
        password,
        password_confirmation: confirm,
      });

      // Resetting signs you in, because the alternative is asking somebody who
      // has just proved who they are to prove it again.
      setToken(res.token);
      location.href = "/circles";
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="relative grid min-h-screen place-items-center px-6 py-12">
      <ThemeToggle className="absolute right-4 top-4" />

      <div className="w-full max-w-[26rem]">
        <div className="mb-8 text-center">
          <div className="inline-flex flex-col items-center gap-3">
            <Wordmark size={22} />
            <p className="text-[0.9375rem] leading-relaxed text-[var(--ink-muted)]">
              {redeeming ? "Choose a new password." : "We'll send you a link."}
            </p>
          </div>
        </div>

        <form
          onSubmit={(e) => {
            e.preventDefault();
            void (redeeming ? redeem() : request());
          }}
          className="titleblock lay-in overflow-hidden"
        >
          <div className="space-y-4 px-5 pb-5 pt-5">
            {sent && !redeeming ? (
              <>
                <p className="text-[0.9375rem] leading-relaxed">
                  If that address has an account, a reset link is on its way.
                </p>
                <p className="text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
                  The link works once and then expires. If it doesn't arrive,
                  check the address and try again.
                </p>
              </>
            ) : (
              <>
                <Field label="Email">
                  <input
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    type="email"
                    className={inputClass}
                    autoComplete="email"
                    readOnly={redeeming && emailFromLink !== ""}
                    required
                  />
                </Field>

                {redeeming && (
                  <>
                    <Field label="New password" hint="At least 12 characters.">
                      <input
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        type="password"
                        className={inputClass}
                        autoComplete="new-password"
                        minLength={12}
                        required
                      />
                    </Field>

                    <Field label="Confirm">
                      <input
                        value={confirm}
                        onChange={(e) => setConfirm(e.target.value)}
                        type="password"
                        className={inputClass}
                        autoComplete="new-password"
                        required
                      />
                    </Field>

                    <p className="text-[0.8125rem] leading-relaxed text-[var(--ink-muted)]">
                      Setting a new password signs you in here and ends every
                      other session on your account.
                    </p>
                  </>
                )}

                <ErrorNote error={error} />

                <Button type="submit" disabled={busy} className="w-full">
                  {busy
                    ? "Working…"
                    : redeeming
                      ? "Set password and sign in"
                      : "Send the link"}
                </Button>
              </>
            )}
          </div>
        </form>

        <p className="mt-5 text-center text-[0.8125rem] text-[var(--ink-muted)]">
          <a href="/login" className="underline underline-offset-2">
            Back to sign in
          </a>
        </p>
      </div>
    </main>
  );
}
